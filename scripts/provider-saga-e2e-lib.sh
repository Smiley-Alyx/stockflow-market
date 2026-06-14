#!/usr/bin/env sh

COMPOSE_FILE="$ROOT_DIR/docker-compose-all.yml"
MARKET_URL=${MARKET_URL:-http://localhost:8080}
ERP_URL=${ERP_URL:-http://localhost:8083}
PAYMENT_URL=${PAYMENT_URL:-http://localhost:8081}
DELIVERY_URL=${DELIVERY_URL:-http://localhost:8082}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-30}
COOKIE_JAR=${TMPDIR:-/tmp}/stockflow-provider-saga-e2e.$$
PROVIDER_SAGA_SEQUENCE=0

provider_saga_json_value() {
    path=$1

    php -r '
        $value = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        foreach (explode(".", $argv[1]) as $segment) {
            $value = $value[$segment];
        }
        echo $value;
    ' "$path"
}

provider_saga_sql_value() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U stockflow -d stockflow -Atqc "$1"
}

provider_saga_assert_running() {
    service=$1

    if [ -z "$(docker compose -f "$COMPOSE_FILE" ps --status running -q "$service")" ]; then
        printf '%s: контейнер не запущен\n' "$service" >&2
        exit 1
    fi
}

provider_saga_set_mode() {
    url=$1
    mode=$2

    curl -fsS -X POST "$url/debug/failure-mode" \
        -H 'content-type: application/json' \
        --data "{\"mode\":\"$mode\"}" >/dev/null
}

provider_saga_reset() {
    curl -fsS -X POST "$PAYMENT_URL/debug/reset" >/dev/null
    curl -fsS -X POST "$DELIVERY_URL/debug/reset" >/dev/null
    provider_saga_set_mode "$ERP_URL" normal
}

provider_saga_cleanup() {
    rm -f "$COOKIE_JAR"
    provider_saga_set_mode "$ERP_URL" normal || true
    provider_saga_set_mode "$PAYMENT_URL" normal || true
    provider_saga_set_mode "$DELIVERY_URL" normal || true
}

provider_saga_prepare() {
    for service in php postgres rabbitmq erp-mock payment-mock payment-mock-worker delivery-mock delivery-mock-worker domain-outbox-worker domain-event-worker provider-outbox-worker provider-outcome-worker; do
        provider_saga_assert_running "$service"
    done

    curl -fsS "$MARKET_URL/health/ready" >/dev/null
    curl -fsS "$ERP_URL/health" >/dev/null
    curl -fsS "$PAYMENT_URL/health" >/dev/null
    curl -fsS "$DELIVERY_URL/health" >/dev/null
    CSRF_TOKEN=$(curl -fsS -c "$COOKIE_JAR" "$MARKET_URL/api/session/csrf" | provider_saga_json_value csrf_token)
}

provider_saga_create_checkout() {
    scenario=$1
    PROVIDER_SAGA_SEQUENCE=$((PROVIDER_SAGA_SEQUENCE + 1))
    SKU="e2e-provider-$scenario-$(date +%s)-$$-$PROVIDER_SAGA_SEQUENCE"

    PRODUCT_ID=$(docker compose -f "$COMPOSE_FILE" exec -T php php artisan tinker --execute="
\$category = App\\Domains\\Catalog\\Models\\Category::query()->firstOrCreate(
    ['slug' => 'e2e-provider'],
    ['name' => 'E2E Provider', 'is_active' => true],
);
\$product = App\\Domains\\Catalog\\Models\\Product::query()->create([
    'category_id' => \$category->id,
    'name' => 'E2E Provider Product',
    'slug' => '$SKU',
    'sku' => '$SKU',
    'status' => 'published',
    'published_at' => now(),
]);
App\\Domains\\Pricing\\Models\\ProductPrice::query()->create([
    'product_id' => \$product->id,
    'price_type' => 'retail',
    'price_version' => 1,
    'amount_minor' => 129900,
    'currency' => 'USD',
    'is_active' => true,
    'active_from' => now()->subMinute(),
]);
echo \$product->id;
")

    curl -fsS -X POST "$ERP_URL/stock" \
        -H 'content-type: application/json' \
        --data "{\"sku\":\"$SKU\",\"available_quantity\":10}" >/dev/null

    CART_ID=$(curl -fsS -X POST "$MARKET_URL/api/cart/items" \
        -b "$COOKIE_JAR" \
        -H 'content-type: application/json' \
        -H "x-csrf-token: $CSRF_TOKEN" \
        --data "{\"product_id\":$PRODUCT_ID,\"quantity\":1}" | provider_saga_json_value data.id)

    DRAFT=$(curl -fsS -X POST "$MARKET_URL/api/orders/draft" \
        -b "$COOKIE_JAR" \
        -H 'content-type: application/json' \
        -H "x-csrf-token: $CSRF_TOKEN" \
        --data "{\"cart_id\":$CART_ID}")
    ORDER_ID=$(printf '%s' "$DRAFT" | provider_saga_json_value data.id)
    ORDER_ITEM_ID=$(printf '%s' "$DRAFT" | provider_saga_json_value data.items.0.id)
    RESERVATION_ID="res-$ORDER_ID-$ORDER_ITEM_ID"

    curl -fsS -X PUT "$MARKET_URL/api/orders/$ORDER_ID/checkout" \
        -b "$COOKIE_JAR" \
        -H 'content-type: application/json' \
        -H "x-csrf-token: $CSRF_TOKEN" \
        --data "{
            \"payment_method\":\"bank_card\",
            \"address\":{
                \"recipient_name\":\"E2E Customer\",
                \"recipient_phone\":\"+79990000000\",
                \"country_code\":\"RU\",
                \"city\":\"Moscow\",
                \"postal_code\":\"101000\",
                \"address_line_1\":\"Red Square 1\"
            },
            \"shipments\":[{
                \"delivery_service\":\"stockflow_courier\",
                \"items\":[{\"order_item_id\":$ORDER_ITEM_ID,\"quantity\":1}]
            }]
        }" >/dev/null

    curl -fsS -X POST "$MARKET_URL/api/orders/$ORDER_ID/confirm" \
        -b "$COOKIE_JAR" \
        -H "x-csrf-token: $CSRF_TOKEN" >/dev/null
}

provider_saga_wait_sql() {
    query=$1
    expected=$2
    description=$3
    attempt=0
    actual=

    while [ "$attempt" -lt "$TIMEOUT_SECONDS" ]; do
        actual=$(provider_saga_sql_value "$query")

        if [ "$actual" = "$expected" ]; then
            return
        fi

        attempt=$((attempt + 1))
        sleep 1
    done

    printf '%s: ожидалось "%s", получено "%s"\n' "$description" "$expected" "$actual" >&2
    exit 1
}

provider_saga_assert_sql() {
    query=$1
    expected=$2
    description=$3
    actual=$(provider_saga_sql_value "$query")

    if [ "$actual" != "$expected" ]; then
        printf '%s: ожидалось "%s", получено "%s"\n' "$description" "$expected" "$actual" >&2
        exit 1
    fi
}

provider_saga_assert_http_json() {
    url=$1
    path=$2
    expected=$3
    description=$4
    actual=$(curl -fsS "$url" | provider_saga_json_value "$path")

    if [ "$actual" != "$expected" ]; then
        printf '%s: ожидалось "%s", получено "%s"\n' "$description" "$expected" "$actual" >&2
        exit 1
    fi
}

provider_saga_assert_published_message() {
    routing_key=$1

    provider_saga_assert_sql \
        "select count(*) from messaging_provider_outbox where correlation_id = (select correlation_id from orders_checkout_sagas where order_id = $ORDER_ID) and routing_key = '$routing_key' and status = 'published';" \
        1 \
        "Количество опубликованных сообщений $routing_key"
}
