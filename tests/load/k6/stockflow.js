import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import exec from 'k6/execution';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const CATALOG_PAGES = Number(__ENV.CATALOG_PAGES || 8);
const CATALOG_PER_PAGE = Number(__ENV.CATALOG_PER_PAGE || 20);
const SEARCH_QUERIES = (__ENV.SEARCH_QUERIES || 'scanner,wireless,market,sku')
    .split(',')
    .map((query) => query.trim())
    .filter(Boolean);
const RESERVATION_SKU = __ENV.RESERVATION_SKU || '';
const RESERVATION_CITY = __ENV.RESERVATION_CITY || '';
const CHECKOUT_PRODUCT_ID = __ENV.CHECKOUT_PRODUCT_ID || '';
const RESERVATION_QUANTITY = Number(__ENV.RESERVATION_QUANTITY || 1);
const CHECKOUT_QUANTITY = Number(__ENV.CHECKOUT_QUANTITY || 1);
let csrfToken = '';

export const options = {
    scenarios: {
        catalog_browse: {
            executor: 'ramping-vus',
            exec: 'catalogBrowse',
            startVUs: 0,
            stages: [
                { duration: __ENV.CATALOG_RAMP_UP || '30s', target: Number(__ENV.CATALOG_VUS || 40) },
                { duration: __ENV.CATALOG_HOLD || '2m', target: Number(__ENV.CATALOG_VUS || 40) },
                { duration: __ENV.CATALOG_RAMP_DOWN || '20s', target: 0 },
            ],
            gracefulRampDown: '20s',
        },
        sku_reservation_race: {
            executor: 'constant-arrival-rate',
            exec: 'skuReservationRace',
            rate: Number(__ENV.RESERVATION_RATE || 25),
            timeUnit: '1s',
            duration: __ENV.RESERVATION_DURATION || '1m',
            preAllocatedVUs: Number(__ENV.RESERVATION_VUS || 30),
            maxVUs: Number(__ENV.RESERVATION_MAX_VUS || 120),
            startTime: __ENV.RESERVATION_START || '10s',
        },
        search_queries: {
            executor: 'ramping-arrival-rate',
            exec: 'searchQueries',
            startRate: 1,
            timeUnit: '1s',
            stages: [
                { duration: __ENV.SEARCH_RAMP_UP || '20s', target: Number(__ENV.SEARCH_RATE || 35) },
                { duration: __ENV.SEARCH_HOLD || '90s', target: Number(__ENV.SEARCH_RATE || 35) },
                { duration: __ENV.SEARCH_RAMP_DOWN || '20s', target: 0 },
            ],
            preAllocatedVUs: Number(__ENV.SEARCH_VUS || 25),
            maxVUs: Number(__ENV.SEARCH_MAX_VUS || 100),
        },
        checkout_burst: {
            executor: 'ramping-arrival-rate',
            exec: 'checkoutBurst',
            startRate: 1,
            timeUnit: '1s',
            stages: [
                { duration: __ENV.CHECKOUT_WARMUP || '15s', target: Number(__ENV.CHECKOUT_BASE_RATE || 5) },
                { duration: __ENV.CHECKOUT_BURST || '30s', target: Number(__ENV.CHECKOUT_BURST_RATE || 30) },
                { duration: __ENV.CHECKOUT_COOLDOWN || '30s', target: Number(__ENV.CHECKOUT_BASE_RATE || 5) },
            ],
            preAllocatedVUs: Number(__ENV.CHECKOUT_VUS || 30),
            maxVUs: Number(__ENV.CHECKOUT_MAX_VUS || 150),
            startTime: __ENV.CHECKOUT_START || '20s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
        http_req_duration: ['p(95)<750', 'p(99)<1500'],
        catalog_errors: ['count<20'],
        reservation_conflicts: ['rate<0.95'],
        checkout_errors: ['rate<0.15'],
    },
};

export const catalogErrors = new Counter('catalog_errors');
export const reservationConflicts = new Rate('reservation_conflicts');
export const checkoutErrors = new Rate('checkout_errors');

export function setup() {
    const ready = http.get(`${BASE_URL}/health/ready`);

    if (ready.status !== 200) {
        throw new Error(`Gateway is not ready at ${BASE_URL}: ${ready.status}`);
    }

    const catalog = http.get(`${BASE_URL}/api/catalog/products?per_page=${CATALOG_PER_PAGE}`);

    if (catalog.status !== 200) {
        throw new Error(`Catalog bootstrap failed: ${catalog.status}`);
    }

    const products = extractProducts(catalog.json());

    if (products.length === 0 && (!CHECKOUT_PRODUCT_ID || !RESERVATION_SKU)) {
        throw new Error('Load tests need catalog products or CHECKOUT_PRODUCT_ID/RESERVATION_SKU overrides.');
    }

    const firstProduct = products[0] || {};

    return {
        productIds: products.map((product) => product.id).filter(Boolean),
        slugs: products.map((product) => product.slug).filter(Boolean),
        reservationSku: RESERVATION_SKU || firstProduct.sku || '',
        checkoutProductId: CHECKOUT_PRODUCT_ID || firstProduct.id || '',
    };
}

export function catalogBrowse(data) {
    group('catalog browse', () => {
        const page = pickNumber(1, CATALOG_PAGES);
        const list = http.get(`${BASE_URL}/api/catalog/products?page=${page}&per_page=${CATALOG_PER_PAGE}`, {
            responseCallback: http.expectedStatuses(200, 429),
            tags: { flow: 'catalog_browse', endpoint: 'catalog_products' },
        });

        const listOk = check(list, {
            'catalog list is 200': (response) => response.status === 200,
        });

        if (!listOk) {
            catalogErrors.add(1);
        }

        const slug = pick(data.slugs);

        if (slug) {
            const detail = http.get(`${BASE_URL}/api/catalog/products/${encodeURIComponent(slug)}`, {
                responseCallback: http.expectedStatuses(200, 404),
                tags: { flow: 'catalog_browse', endpoint: 'catalog_product_detail' },
            });

            const detailOk = check(detail, {
                'catalog detail is 200 or 404': (response) => response.status === 200 || response.status === 404,
            });

            if (!detailOk) {
                catalogErrors.add(1);
            }
        }

        sleep(Math.random() * 0.4);
    });
}

export function skuReservationRace(data) {
    const sku = data.reservationSku;

    if (!sku) {
        reservationConflicts.add(true);
        return;
    }

    const idempotencyKey = `k6-reserve-${exec.scenario.iterationInTest}-${exec.vu.idInTest}`;
    const payload = {
        sku,
        quantity: RESERVATION_QUANTITY,
        reservation_expires_at: new Date(Date.now() + 10 * 60 * 1000).toISOString(),
    };

    if (RESERVATION_CITY) {
        payload.city_code = RESERVATION_CITY;
    }

    const response = http.post(`${BASE_URL}/api/inventory/reservations`, JSON.stringify(payload), {
        headers: {
            'Content-Type': 'application/json',
            'Idempotency-Key': idempotencyKey,
            ...csrfHeaders(),
        },
        responseCallback: http.expectedStatuses(201, 404, 409, 422, 429),
        tags: { flow: 'sku_reservation_race', endpoint: 'inventory_reservations' },
    });

    check(response, {
        'reservation returns expected status': (res) => [201, 404, 409, 422, 429].includes(res.status),
    });
    reservationConflicts.add(response.status === 409);
}

export function searchQueries() {
    const query = encodeURIComponent(pick(SEARCH_QUERIES));
    const page = pickNumber(1, 3);
    const response = http.get(`${BASE_URL}/api/search/products?q=${query}&page=${page}&per_page=20`, {
        responseCallback: http.expectedStatuses(200, 429, 503),
        tags: { flow: 'search_queries', endpoint: 'search_products' },
    });

    check(response, {
        'search returns expected status': (res) => [200, 429, 503].includes(res.status),
    });

    sleep(Math.random() * 0.25);
}

export function checkoutBurst(data) {
    const productId = data.checkoutProductId;

    if (!productId) {
        checkoutErrors.add(true);
        return;
    }

    group('checkout burst', () => {
        const cart = http.post(
            `${BASE_URL}/api/cart/items`,
            JSON.stringify({ product_id: Number(productId), quantity: CHECKOUT_QUANTITY }),
            jsonParams('checkout_burst', 'cart_items'),
        );
        const cartOk = check(cart, {
            'cart item created': (response) => response.status === 201,
        });

        if (!cartOk) {
            checkoutErrors.add(true);
            return;
        }

        const cartId = cart.json('data.id');
        const draft = http.post(
            `${BASE_URL}/api/orders/draft`,
            JSON.stringify({ cart_id: cartId }),
            jsonParams('checkout_burst', 'orders_draft'),
        );
        const draftOk = check(draft, {
            'draft order created': (response) => response.status === 201,
        });

        if (!draftOk) {
            checkoutErrors.add(true);
            return;
        }

        const orderId = draft.json('data.id');
        const confirm = http.post(
            `${BASE_URL}/api/orders/${orderId}/confirm`,
            null,
            jsonParams('checkout_burst', 'orders_confirm'),
        );

        const confirmOk = check(confirm, {
            'order confirm accepted': (response) => [200, 409, 429].includes(response.status),
        });

        checkoutErrors.add(!confirmOk || confirm.status >= 500);
    });
}

function extractProducts(payload) {
    if (!payload) {
        return [];
    }

    if (Array.isArray(payload.data)) {
        return payload.data;
    }

    if (Array.isArray(payload.data?.data)) {
        return payload.data.data;
    }

    return [];
}

function jsonParams(flow, endpoint) {
    return {
        headers: { 'Content-Type': 'application/json', ...csrfHeaders() },
        responseCallback: http.expectedStatuses(200, 201, 409, 429),
        tags: { flow, endpoint },
    };
}

function csrfHeaders() {
    if (!csrfToken) {
        const response = http.get(`${BASE_URL}/api/session/csrf`, {
            responseCallback: http.expectedStatuses(200),
            tags: { flow: 'session', endpoint: 'session_csrf' },
        });

        if (response.status !== 200) {
            return {};
        }

        csrfToken = response.json('csrf_token');
    }

    return { 'X-CSRF-TOKEN': csrfToken };
}

function pick(items) {
    if (!items || items.length === 0) {
        return '';
    }

    return items[Math.floor(Math.random() * items.length)];
}

function pickNumber(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}
