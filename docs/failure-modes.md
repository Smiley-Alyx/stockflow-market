# Сценарии отказов и гарантии

## Матрица отказов

| Сценарий | Ожидаемое поведение | Компенсация или recovery |
| --- | --- | --- |
| ERP отклонил резерв | Заказ не подтверждается | Ничего освобождать не нужно |
| ERP временно недоступен | Request проходит retry и затем DLQ | После восстановления оператор делает requeue |
| Payment authorization declined | Checkout отменяется | Market публикует release резерва |
| Capture failed | Заказ не переходит в paid | Market публикует release резерва |
| Shipment creation failed после capture | Fulfillment не стартует | Market публикует refund, затем release резерва |
| Дублированный request | Provider replay-ит сохранённый результат | Side effect выполняется один раз |
| Дублированный outcome | Market inbox пропускает повторный transition | Saga state не меняется повторно |
| Невалидный payload | Сообщение не обрабатывается как бизнес-команда | DLQ и ручной разбор |
| Broker недоступен при публикации market | Outbox сохраняет событие | Relay повторяет публикацию после восстановления |

## Таблица гарантий

| Гарантия | ERP mock | Payment mock | Delivery mock | Market |
| --- | --- | --- | --- | --- |
| At-least-once delivery | Да, durable queue + retry | Да, durable queue + retry | Да, durable queue + retry | Да, durable outcomes queue + requeue |
| Idempotency | Да, in-memory store | Да, domain records + published event store | Да, in-memory records + published event store | Да, inbox по `message_id` и idempotent saga transitions |
| DLQ | Да, отдельные DLQ по reservation flow | Да, `stockflow.payment.requests.dlq` | Да, `stockflow.delivery.requests.dlq` | Да, `stockflow.market.provider.outcomes.dlq` для provider outcomes |
| Retry | TTL retry queues, по умолчанию 3 попытки | Retry queue, по умолчанию 3 попытки | Retry queue, по умолчанию 3 попытки | Да, outbox relay с backoff и stale claim recovery |
| Correlation tracing | `correlation_id`, `causation_id` | `correlation_id`, `causation_id` | `correlation_id`, `causation_id` | Да, один `correlation_id` на saga |

## Инъекция отказов

Моки позволяют воспроизводить отказ без изменения кода:

| Boundary | Пример режима | Команда |
| --- | --- | --- |
| ERP | `always_reject` | `curl -X POST http://localhost:8083/debug/failure-mode -H 'content-type: application/json' -d '{"mode":"always_reject"}'` |
| Payment | `provider_unavailable` | `curl -X POST http://localhost:8081/debug/failure-mode -H 'content-type: application/json' -d '{"mode":"provider_unavailable"}'` |
| Delivery | `provider_unavailable` | `curl -X POST http://localhost:8082/debug/failure-mode -H 'content-type: application/json' -d '{"mode":"provider_unavailable"}'` |

Точные режимы и recovery-команды находятся в sibling docs:

- [ERP failure modes](https://github.com/Smiley-Alyx/stockflow-erp-mock/blob/main/docs/failure-modes.md)
- [Payment failure modes](https://github.com/Smiley-Alyx/stockflow-payment-mock/blob/main/docs/failure-modes.md)
- [Delivery failure modes](https://github.com/Smiley-Alyx/stockflow-delivery-mock/blob/main/docs/failure-modes.md)

## Ограничения общего стенда

Общий compose поднимает runtime, broker topology, market relay, outcome consumer
и три provider worker. Автономный broker-level E2E happy path сам поднимает
стенд, прогоняет один checkout и останавливает созданные контейнеры:

```bash
./scripts/test-broker-checkout-e2e.sh
```

Для уже запущенного стенда broker-level E2E сценарии запускаются командами:

```bash
./scripts/test-provider-saga-e2e.sh
./scripts/test-provider-saga-compensations-e2e.sh
```

Второй сценарий проверяет освобождение ERP-резерва после отказа capture, а также
refund и освобождение резерва после отказа создания shipment.

Market outcome DLQ можно просмотреть и вернуть в обработку ограниченным batch:

```bash
docker compose -f docker-compose-all.yml exec php \
  php artisan messaging:provider-outcomes:dead-letter list --limit=20

docker compose -f docker-compose-all.yml exec php \
  php artisan messaging:provider-outcomes:dead-letter requeue --limit=20
```
