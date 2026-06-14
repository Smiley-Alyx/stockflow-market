# Prometheus alert rules

Prometheus загружает правила из
[`docker/prometheus/rules/stockflow-alerts.yml`](../docker/prometheus/rules/stockflow-alerts.yml).
Gateway scrape предоставляет HTTP latency, Redis queue depth и search
dead-letter count. RabbitMQ scrape использует `/metrics/per-object`, чтобы
сохранять label `queue` для основных очередей и DLQ.

## Правила

| Alert | Порог | Назначение |
| --- | --- | --- |
| `StockflowDeadLetterGrowth` | search или RabbitMQ DLQ выросла за `15m` и сигнал сохраняется `1m` | Требует немедленной диагностики необработанных сообщений |
| `StockflowQueueBacklogHigh` | основная очередь содержит больше `100` ready сообщений в течение `10m` | Показывает устойчивое отставание consumer |
| `StockflowHttpLatencyP95High` | p95 HTTP latency по endpoint выше `1s` в течение `10m` | Показывает устойчивую деградацию gateway |

Правила содержат только сигнализацию. Маршрутизация уведомлений через
Alertmanager не входит в локальный Compose-стенд.

## Проверка

Проверить конфигурацию и правила:

```bash
docker run --rm \
  --entrypoint promtool \
  -v "$PWD/docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro" \
  -v "$PWD/docker/prometheus/rules:/etc/prometheus/rules:ro" \
  prom/prometheus:v3.8.0 \
  check config /etc/prometheus/prometheus.yml

docker run --rm \
  --entrypoint promtool \
  -v "$PWD/docker/prometheus/rules:/etc/prometheus/rules:ro" \
  -w /etc/prometheus/rules \
  prom/prometheus:v3.8.0 \
  test rules stockflow-alerts.test.yml
```

После запуска стенда состояние правил доступно в Prometheus:

```text
http://localhost:9090/alerts
```

При `StockflowDeadLetterGrowth` сначала остановить массовый requeue и определить
причину ошибки. Для provider outcomes использовать
[`provider-outcome-dlq-runbook.md`](provider-outcome-dlq-runbook.md).
При `StockflowQueueBacklogHigh` проверить consumers, глубину retry queues и
доступность зависимостей. При `StockflowHttpLatencyP95High` сравнить endpoint с
нагрузочным baseline и проверить PostgreSQL, Redis и downstream-зависимости.
