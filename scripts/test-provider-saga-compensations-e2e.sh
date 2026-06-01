#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

. "$ROOT_DIR/scripts/provider-saga-e2e-lib.sh"

trap provider_saga_cleanup EXIT

provider_saga_prepare

provider_saga_reset
provider_saga_set_mode "$PAYMENT_URL" capture_failure
provider_saga_create_checkout capture-failure

provider_saga_wait_sql \
    "select status from orders_checkout_sagas where order_id = $ORDER_ID;" \
    failed \
    "Saga заказа $ORDER_ID не завершилась отказом capture"
provider_saga_wait_sql \
    "select status from orders_checkout_saga_reservations where reservation_id = '$RESERVATION_ID';" \
    released \
    "Резерв $RESERVATION_ID не освобождён после отказа capture"
provider_saga_assert_sql \
    "select status from orders_orders where id = $ORDER_ID;" \
    reservation_failed \
    "Статус заказа $ORDER_ID после отказа capture"
provider_saga_assert_http_json "$PAYMENT_URL/payments/pay_$ORDER_ID" data.status capture_failed "Статус платежа pay_$ORDER_ID"
provider_saga_assert_http_json "$ERP_URL/reservations/$RESERVATION_ID" status released "Статус ERP-резерва $RESERVATION_ID"
provider_saga_assert_published_message inventory.reservation.release.requested.v1

printf 'Компенсация capture failure завершена: order_id=%s reservation_id=%s\n' "$ORDER_ID" "$RESERVATION_ID"

provider_saga_reset
provider_saga_set_mode "$DELIVERY_URL" invalid_address
provider_saga_create_checkout delivery-failure

provider_saga_wait_sql \
    "select status from orders_checkout_sagas where order_id = $ORDER_ID;" \
    failed \
    "Saga заказа $ORDER_ID не завершилась отказом delivery"
provider_saga_wait_sql \
    "select refund_status from orders_checkout_sagas where order_id = $ORDER_ID;" \
    completed \
    "Платёж заказа $ORDER_ID не возвращён после отказа delivery"
provider_saga_wait_sql \
    "select status from orders_checkout_saga_reservations where reservation_id = '$RESERVATION_ID';" \
    released \
    "Резерв $RESERVATION_ID не освобождён после отказа delivery"
provider_saga_assert_sql \
    "select status from orders_orders where id = $ORDER_ID;" \
    paid \
    "Статус заказа $ORDER_ID после отказа delivery"
provider_saga_assert_http_json "$PAYMENT_URL/payments/pay_$ORDER_ID" data.status refunded "Статус платежа pay_$ORDER_ID"
provider_saga_assert_http_json "$ERP_URL/reservations/$RESERVATION_ID" status released "Статус ERP-резерва $RESERVATION_ID"
provider_saga_assert_published_message payment.refund.requested.v1
provider_saga_assert_published_message inventory.reservation.release.requested.v1

printf 'Компенсация delivery failure завершена: order_id=%s reservation_id=%s\n' "$ORDER_ID" "$RESERVATION_ID"
