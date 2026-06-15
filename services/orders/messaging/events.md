# События заказов

- `orders.order.created`
- `order.confirmation.requested`
- `order.reservation_succeeded`
- `order.reservation_failed`
- `orders.order.paid`
- `orders.order.cancelled`
- `orders.order.expired`

# Входящие события остатков

- `inventory.reserve`

В общем стенде события публикуются через topic exchange
`stockflow.domain.events`. Market consumer использует at-least-once delivery,
inbox-дедупликацию, TTL retry queue и DLQ.

Статусы резервирования для gateway читаются из
`orders_reservation_status_projections`. Saga write-модель
`orders_checkout_saga_reservations` используется только для оркестрации и
компенсаций.
