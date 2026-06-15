# События остатков

- `inventory.stock.received`
- `inventory.stock.reserved`
- `inventory.stock.released`
- `inventory.stock.expired`
- `inventory.stock.deducted`
- `inventory.stock.returned`
- `inventory.stock.changed`
- `inventory.reserve`

# Provider saga

- `inventory.reservation.requested.v1`
- `inventory.reservation.confirmed.v1`
- `inventory.reservation.rejected.v1`
- `inventory.reservation.release.requested.v1`
- `inventory.reservation.released.v1`
- `inventory.reservation.release_failed.v1`

Market consumer строит из request и outcome сообщений отдельную асинхронную
проекцию `orders_reservation_status_projections`, которую читает checkout API.
