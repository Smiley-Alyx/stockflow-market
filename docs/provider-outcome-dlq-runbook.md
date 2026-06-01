# Runbook provider outcome DLQ

## Назначение

Runbook описывает разбор сообщений из market-очереди
`stockflow.market.provider.outcomes.dlq`. В неё попадают outcomes ERP, payment и
delivery provider после исчерпания retry в `provider-outcome-worker`.

Команда ручного возврата работает с ограниченным batch через `--limit`. Выбрать
отдельный `message_id` сейчас нельзя, поэтому перед requeue нужно просмотреть
тот же верхний batch DLQ.

## 1. Найти сообщения

Проверить worker и глубину outcomes-очередей:

```bash
docker compose -f docker-compose-all.yml ps provider-outcome-worker

docker compose -f docker-compose-all.yml exec -T rabbitmq \
  rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers |
  grep 'stockflow.market.provider.outcomes'
```

Просмотреть первые сообщения, не удаляя их из DLQ:

```bash
docker compose -f docker-compose-all.yml exec -T php \
  php artisan messaging:provider-outcomes:dead-letter list --limit=20
```

Команда выводит `routing_key`, `message_id`, `correlation_id`, `retry_count` и
`payload`. Для дальнейшего разбора сохранить `message_id` и `correlation_id`
нужного сообщения.

## 2. Диагностировать причину

Посмотреть ошибки market consumer и соответствующего provider:

```bash
docker compose -f docker-compose-all.yml logs --since=30m provider-outcome-worker
docker compose -f docker-compose-all.yml logs --since=30m \
  erp-mock payment-mock-worker delivery-mock-worker
```

Найти состояние saga по `correlation_id` из DLQ:

```bash
CORRELATION_ID=<CORRELATION_ID>

docker compose -f docker-compose-all.yml exec -T postgres \
  psql -U stockflow -d stockflow -v correlation_id="$CORRELATION_ID" <<'SQL'
select id, order_id, correlation_id, status, failure_reason, refund_status,
       compensation_failure_reason, updated_at
from orders_checkout_sagas
where correlation_id = :'correlation_id';

select r.reservation_id, r.status, r.updated_at
from orders_checkout_saga_reservations r
join orders_checkout_sagas s on s.id = r.checkout_saga_id
where s.correlation_id = :'correlation_id';

select p.id, p.exchange, p.routing_key, p.status, p.attempts, p.last_error,
       p.created_at, p.published_at
from messaging_provider_outbox p
where p.correlation_id = :'correlation_id'
order by p.id;
SQL
```

Проверить inbox по `message_id` из DLQ:

```bash
MESSAGE_ID=<MESSAGE_ID>

docker compose -f docker-compose-all.yml exec -T postgres \
  psql -U stockflow -d stockflow -v message_id="$MESSAGE_ID" <<'SQL'
select message_id, consumer, status, last_error, created_at, updated_at,
       processed_at
from messaging_inbox
where message_id = :'message_id';
SQL
```

Перед requeue определить причину:

| Ситуация | Действие |
| --- | --- |
| Временная недоступность PostgreSQL, RabbitMQ или worker устранена | Выполнить ограниченный requeue |
| Исправлена ошибка market consumer или совместимость контракта | Развернуть исправление, затем выполнить ограниченный requeue |
| В payload отсутствуют обязательные поля или заголовки `message_id`, `correlation_id` | Не возвращать сообщение циклически; исправить producer или контракт |
| Для `correlation_id` нет saga | Не возвращать сообщение циклически; проверить источник сообщения и окружение |
| Причина не определена | Оставить сообщение в DLQ и эскалировать с payload, логами и состоянием saga |

## 3. Вернуть сообщения

Сначала повторно вывести небольшой batch и убедиться, что в него входят только
диагностированные сообщения:

```bash
docker compose -f docker-compose-all.yml exec -T php \
  php artisan messaging:provider-outcomes:dead-letter list --limit=1
```

Вернуть одно сообщение:

```bash
docker compose -f docker-compose-all.yml exec -T php \
  php artisan messaging:provider-outcomes:dead-letter requeue --limit=1
```

Для подтверждённой однотипной причины можно увеличить `--limit`. Команда
сбрасывает `retry_count` в `0`, публикует сообщения обратно в основную
outcomes-очередь и удаляет их из DLQ только после publisher confirm RabbitMQ.

## 4. Проверить восстановление

Проверить DLQ, worker и состояние saga:

```bash
docker compose -f docker-compose-all.yml exec -T php \
  php artisan messaging:provider-outcomes:dead-letter list --limit=20

docker compose -f docker-compose-all.yml ps provider-outcome-worker
docker compose -f docker-compose-all.yml logs --since=5m provider-outcome-worker
```

Повторно выполнить SQL-запросы из шага 2. Убедиться, что saga перешла в
ожидаемое состояние, а новые provider requests при необходимости появились в
`messaging_provider_outbox`.

Если сообщение снова оказалось в DLQ, не повторять массовый requeue. Остановить
цикл ручных возвратов, собрать логи и проверить payload, контракт и состояние
saga.

## Конфигурация

| Переменная | Значение по умолчанию | Назначение |
| --- | --- | --- |
| `STOCKFLOW_PROVIDER_OUTCOMES_QUEUE` | `stockflow.market.provider.outcomes` | Основная очередь outcomes |
| `STOCKFLOW_PROVIDER_OUTCOMES_RETRY_QUEUE` | `stockflow.market.provider.outcomes.retry` | Очередь retry с TTL |
| `STOCKFLOW_PROVIDER_OUTCOMES_DLQ` | `stockflow.market.provider.outcomes.dlq` | Очередь ручного разбора |
| `STOCKFLOW_PROVIDER_OUTCOMES_RETRY_DELAY_MS` | `2000` | Задержка перед повторной доставкой |
| `STOCKFLOW_PROVIDER_OUTCOMES_MAX_RETRY_COUNT` | `3` | Число retry до DLQ |
