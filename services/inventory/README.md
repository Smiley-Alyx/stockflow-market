# Inventory Service

Сервис остатков отвечает за наличие товара, резервы и складские движения.

## Модель

- `inventory_stock_items` — текущий складской остаток по товару и складу.
- `inventory_stock_movements` — журнал изменений остатка и резервов.
- `inventory_stock_movement_archives` — холодный архив движений, выгружаемый из горячего журнала по retention.
- `inventory_reservations` — активные, отменённые и истёкшие резервы с `idempotency_key` и `reservation_expires_at`.

Резервирование выполняется атомарным условным обновлением `reserved_quantity`, поэтому 100 конкурентных заказов на 10 единиц не могут зарезервировать больше доступного остатка. Повтор reserve-запроса с тем же `idempotency_key` возвращает тот же резерв, а изменение формы запроса с тем же ключом отклоняется конфликтом.

## Масштабирование движений

Горячий журнал `inventory_stock_movements` индексирован под чтение последних событий по `(stock_item_id, occurred_at, id)`, фильтр по типу и retention-скан `(occurred_at, id)`. API `/api/inventory/stock-movements` использует cursor-пагинацию по паре `occurred_at,id`, поэтому глубокие страницы не требуют дорогого `OFFSET`.

Retention выполняет команда `inventory:stock-movements:archive`: она пачками переносит движения старше `STOCKFLOW_STOCK_MOVEMENT_RETENTION_DAYS` в `inventory_stock_movement_archives` и удаляет их из горячей таблицы. Размер транзакционной пачки задаётся `STOCKFLOW_STOCK_MOVEMENT_ARCHIVE_BATCH_SIZE`; `--dry-run` показывает объём без переноса. Для PostgreSQL прод-кластера эта же граница retention является естественным ключом месячных range-partitions по `occurred_at`: горячие партиции остаются в основной таблице, закрытые месяцы можно detach/drop после архивации.

## Аналитическая витрина

При `CLICKHOUSE_ENABLED=true` outbox-обработчик проецирует события
`inventory.stock.changed` в ClickHouse-таблицу `inventory_stock_movements`.
Consumer использует общий inbox, поэтому повторная доставка одного события не
создаёт повторную вставку. Таблица партиционирована по месяцу `occurred_at` и
хранит идентификаторы товара, склада и движения вместе с типом и количеством
изменения.

Команда `analytics:stock-movements:rebuild` создаёт таблицу при необходимости,
очищает витрину и восстанавливает её из горячего журнала и
`inventory_stock_movement_archives`. Размер HTTP-пачки задаётся
`STOCKFLOW_ANALYTICS_STOCK_MOVEMENT_REBUILD_BATCH_SIZE`.

## Зоны

- `contracts/` — HTTP/API-контракты сервиса.
- `database/migrations/` — миграции собственной схемы сервиса.
- `messaging/` — исходящие и входящие события.
- `src/` — прикладной и доменный код.
- `tests/` — модульные, контрактные и интеграционные проверки.
