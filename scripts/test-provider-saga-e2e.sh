#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

. "$ROOT_DIR/scripts/provider-saga-e2e-lib.sh"

trap provider_saga_cleanup EXIT

provider_saga_prepare
provider_saga_reset
provider_saga_create_checkout happy

provider_saga_wait_sql \
    "select status from orders_checkout_sagas where order_id = $ORDER_ID;" \
    completed \
    "Saga заказа $ORDER_ID не завершилась"

provider_saga_assert_sql \
    "select status from orders_orders where id = $ORDER_ID;" \
    paid \
    "Статус заказа $ORDER_ID"

published_messages=$(provider_saga_sql_value "select count(*) from messaging_provider_outbox where correlation_id = (select correlation_id from orders_checkout_sagas where order_id = $ORDER_ID) and status = 'published';")

if [ "$published_messages" -lt 4 ]; then
    printf 'Для заказа %s опубликовано недостаточно provider-сообщений: %s\n' "$ORDER_ID" "$published_messages" >&2
    exit 1
fi

curl -fsS "$PAYMENT_URL/payments/pay_$ORDER_ID" >/dev/null
curl -fsS "$ERP_URL/reservations/$RESERVATION_ID" >/dev/null

printf 'Provider saga E2E завершена: order_id=%s sku=%s provider_messages=%s\n' "$ORDER_ID" "$SKU" "$published_messages"
