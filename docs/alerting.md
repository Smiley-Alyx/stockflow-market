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
| `StockflowRabbitMqUnavailable` | обязательный RabbitMQ не scrape-ится в течение `1m` | Показывает недоступность broker для runtime, где он включён |
| `StockflowConsumerDown` | очередь market остаётся без consumer в течение `1m` | Показывает остановленный domain-event или provider-outcome worker |

Prometheus отправляет alerts в Alertmanager. Локальный receiver сохраняет
доставленные firing/resolved уведомления и доступен по адресу
`http://localhost:9081/events`. Alertmanager UI доступен по адресу
`http://localhost:9093`.

Очистить историю локального receiver:

```bash
curl -fsS -X POST http://localhost:9081/reset
```

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

docker run --rm \
  --entrypoint amtool \
  -v "$PWD/docker/alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro" \
  prom/alertmanager:v0.28.1 \
  check-config /etc/alertmanager/alertmanager.yml
```

После запуска стенда состояние правил доступно в Prometheus:

```text
http://localhost:9090/alerts
```

Состояние доставки и группировки доступно в Alertmanager:

```text
http://localhost:9093
```

При `StockflowDeadLetterGrowth` сначала остановить массовый requeue и определить
причину ошибки. Для provider outcomes использовать
[`provider-outcome-dlq-runbook.md`](provider-outcome-dlq-runbook.md).
При `StockflowQueueBacklogHigh` проверить consumers, глубину retry queues и
доступность зависимостей. При `StockflowHttpLatencyP95High` сравнить endpoint с
нагрузочным baseline и проверить PostgreSQL, Redis и downstream-зависимости.
