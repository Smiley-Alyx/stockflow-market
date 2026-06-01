#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose-all.yml"
MARKET_URL=${MARKET_URL:-http://localhost:8080}
ERP_URL=${ERP_URL:-http://localhost:8083}
PAYMENT_URL=${PAYMENT_URL:-http://localhost:8081}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-30}
SUFFIX=$(date +%s)
SKU="e2e-provider-$SUFFIX"
COOKIE_JAR=${TMPDIR:-/tmp}/stockflow-provider-saga-e2e.$$

trap 'rm -f "$COOKIE_JAR"' EXIT

json_value() {
    path=$1

    php -r '
        $value = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        foreach (explode(".", $argv[1]) as $segment) {
            $value = $value[$segment];
        }
        echo $value;
    ' "$path"
}

sql_value() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U stockflow -d stockflow -Atqc "$1"
}

assert_running() {
    service=$1

    if [ -z "$(docker compose -f "$COMPOSE_FILE" ps --status running -q "$service")" ]; then
        printf '%s: контейнер не запущен\n' "$service" >&2
        exit 1
    fi
}

for service in php postgres rabbitmq erp-mock payment-mock-worker delivery-mock-worker domain-outbox-worker provider-outbox-worker provider-outcome-worker; do
    assert_running "$service"
done

curl -fsS "$MARKET_URL/health/ready" >/dev/null
curl -fsS "$ERP_URL/health" >/dev/null
curl -fsS "$PAYMENT_URL/health" >/dev/null
CSRF_TOKEN=$(curl -fsS -c "$COOKIE_JAR" "$MARKET_URL/api/session/csrf" | json_value csrf_token)

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
    --data "{\"product_id\":$PRODUCT_ID,\"quantity\":1}" | json_value data.id)

DRAFT=$(curl -fsS -X POST "$MARKET_URL/api/orders/draft" \
    -b "$COOKIE_JAR" \
    -H 'content-type: application/json' \
    -H "x-csrf-token: $CSRF_TOKEN" \
    --data "{\"cart_id\":$CART_ID}")
ORDER_ID=$(printf '%s' "$DRAFT" | json_value data.id)
ORDER_ITEM_ID=$(printf '%s' "$DRAFT" | json_value data.items.0.id)

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

attempt=0
status=
while [ "$attempt" -lt "$TIMEOUT_SECONDS" ]; do
    status=$(sql_value "select status from orders_checkout_sagas where order_id = $ORDER_ID;")

    if [ "$status" = "completed" ]; then
        break
    fi

    attempt=$((attempt + 1))
    sleep 1
done

if [ "$status" != "completed" ]; then
    printf 'Saga заказа %s не завершилась за %s секунд, текущий статус: %s\n' "$ORDER_ID" "$TIMEOUT_SECONDS" "$status" >&2
    exit 1
fi

order_status=$(sql_value "select status from orders_orders where id = $ORDER_ID;")
published_messages=$(sql_value "select count(*) from messaging_provider_outbox where correlation_id = (select correlation_id from orders_checkout_sagas where order_id = $ORDER_ID) and status = 'published';")

if [ "$order_status" != "paid" ]; then
    printf 'Заказ %s имеет неожиданный статус: %s\n' "$ORDER_ID" "$order_status" >&2
    exit 1
fi

if [ "$published_messages" -lt 4 ]; then
    printf 'Для заказа %s опубликовано недостаточно provider-сообщений: %s\n' "$ORDER_ID" "$published_messages" >&2
    exit 1
fi

curl -fsS "$PAYMENT_URL/payments/pay_$ORDER_ID" >/dev/null
curl -fsS "$ERP_URL/reservations/res-$ORDER_ID-$ORDER_ITEM_ID" >/dev/null

printf 'Provider saga E2E завершена: order_id=%s sku=%s provider_messages=%s\n' "$ORDER_ID" "$SKU" "$published_messages"
