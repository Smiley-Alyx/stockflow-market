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
