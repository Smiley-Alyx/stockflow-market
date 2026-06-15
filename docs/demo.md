# Демо экосистемы за 5 минут

## Цель

Показать техлиду границы четырёх систем, единый RabbitMQ, доступность provider
sandbox-сервисов,
контрактный checkout flow и готовые механизмы reliability.

## 0:00–1:00 — поднять стенд

Репозитории должны лежать рядом:

```text
projects/
  stockflow-market/
  stockflow-erp-mock/
  stockflow-payment-mock/
  stockflow-delivery-mock/
```

Запуск:

```bash
./scripts/demo-all.sh
```

Скрипт собирает контейнеры, запускает общий broker, применяет market migrations,
проверяет HTTP endpoints и убеждается, что фоновые consumers и workers
запущены.

## 1:00–2:00 — показать экосистему

Откройте [README](../README.md#экосистема-stockflow) и проговорите:

1. `stockflow-market` хранит checkout и должен оркестрировать saga.
2. ERP sandbox резервирует остатки через `stockflow.inventory`.
3. Payment sandbox моделирует authorize/capture/refund через `stockflow.payment`.
4. Delivery sandbox создаёт отправления через `stockflow.delivery`.
5. Все сообщения checkout связываются одним `correlation_id`.

Целевая sequence diagram находится в
[`delivery-flow.md`](delivery-flow.md#happy-path).

## 2:00–3:00 — проверить runtime

```bash
curl -s http://localhost:8080/health/ready
curl -s http://localhost:8083/stock | jq
curl -s http://localhost:8081/sandbox/cards | jq
curl -s http://localhost:8082/health | jq
```

RabbitMQ management UI: `http://localhost:15672`, логин и пароль:
`stockflow / stockflow`.

## 3:00–4:00 — показать reliability

Откройте [`failure-modes.md`](failure-modes.md#таблица-гарантий) и покажите пять
гарантий: at-least-once delivery, idempotency, DLQ, retry и correlation tracing.

Быстрый fault injection для ERP:

```bash
curl -s -X POST http://localhost:8083/debug/failure-mode \
  -H 'content-type: application/json' \
  --data '{"mode":"always_reject"}' | jq

curl -s -X POST http://localhost:8083/debug/failure-mode \
  -H 'content-type: application/json' \
  --data '{"mode":"normal"}' | jq
```

## 4:00–5:00 — обозначить границу готовности

Сформулируйте явно:

- sandbox providers готовы к автономной интеграции и failure testing;
- market provider saga публикует requests через outbox relay и дедуплицирует
  outcomes через inbox;
- broker-level E2E тест автоматически поднимает общий стенд и прогоняет один
  checkout;
- runbook outcome DLQ описывает поиск, диагностику и ограниченный requeue;
- Prometheus alert rules контролируют рост DLQ, backlog очередей и HTTP p95
  latency.

Это важная граница: compose запускает всю экосистему и market-orchestrator, а
broker-level E2E сценарий проверяет happy path через реальный RabbitMQ.

## Полезные команды

```bash
docker compose -f docker-compose-all.yml ps
docker compose -f docker-compose-all.yml logs -f erp-mock payment-mock-worker delivery-mock-worker
docker compose -f docker-compose-all.yml exec php php artisan messaging:provider-outcomes:dead-letter list --limit=20
./scripts/test-broker-checkout-e2e.sh
./scripts/test-provider-saga-e2e.sh
./scripts/test-provider-saga-compensations-e2e.sh
./scripts/test-domain-event-redelivery-e2e.sh
docker compose -f docker-compose-all.yml down
```

Полный порядок разбора outcome DLQ находится в
[`provider-outcome-dlq-runbook.md`](provider-outcome-dlq-runbook.md).
