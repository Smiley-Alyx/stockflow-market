#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose-all.yml"
ALERTS_URL=${ALERTS_URL:-http://localhost:9081}
MARKET_URL=${MARKET_URL:-http://localhost:8080}
PROMETHEUS_URL=${PROMETHEUS_URL:-http://localhost:9090}
RABBITMQ_API=${RABBITMQ_API:-http://localhost:15672/api}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-240}
STACK_WAS_RUNNING=false
LATENCY_PID=
DLQ_NAME=stockflow.alert-drill.dlq

wait_for_url() {
    name=$1
    url=$2
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS "$url" >/dev/null 2>&1; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf '%s: endpoint не ответил вовремя (%s)\n' "$name" "$url" >&2
    exit 1
}

wait_for_alert_event() {
    alertname=$1
    status=$2
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS "$ALERTS_URL/events" |
            jq -e --arg alertname "$alertname" --arg status "$status" \
                '.events | any(.labels.alertname == $alertname and .status == $status)' >/dev/null; then
            printf '%s: получено состояние %s\n' "$alertname" "$status"
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf '%s: состояние %s не доставлено вовремя\n' "$alertname" "$status" >&2
    exit 1
}

wait_for_rabbitmq() {
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS -u stockflow:stockflow "$RABBITMQ_API/overview" >/dev/null 2>&1; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf 'RabbitMQ management: endpoint не ответил вовремя\n' >&2
    exit 1
}

wait_for_prometheus_series() {
    query=$1
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS --get "$PROMETHEUS_URL/api/v1/query" --data-urlencode "query=$query" |
            jq -e '.data.result | length > 0' >/dev/null; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf 'Prometheus: series не появилась вовремя (%s)\n' "$query" >&2
    exit 1
}

reset_alerts() {
    curl -fsS -X POST "$ALERTS_URL/reset" >/dev/null
}

rabbitmq_api() {
    method=$1
    path=$2
    body=${3:-}

    if [ -n "$body" ]; then
        curl -fsS -u stockflow:stockflow -X "$method" "$RABBITMQ_API/$path" \
            -H 'content-type: application/json' \
            --data "$body" >/dev/null
    else
        curl -fsS -u stockflow:stockflow -X "$method" "$RABBITMQ_API/$path" >/dev/null
    fi
}

start_latency() {
    (
        while true; do
            curl -fsS "$MARKET_URL/debug/observability/latency/1500" >/dev/null
        done
    ) &
    LATENCY_PID=$!
}

stop_latency() {
    if [ -n "$LATENCY_PID" ]; then
        kill "$LATENCY_PID" 2>/dev/null || true
        wait "$LATENCY_PID" 2>/dev/null || true
        LATENCY_PID=
    fi
}

cleanup() {
    stop_latency
    rabbitmq_api DELETE "queues/%2F/$DLQ_NAME" 2>/dev/null || true
    docker compose -f "$COMPOSE_FILE" start rabbitmq domain-event-worker >/dev/null 2>&1 || true

    if [ "$STACK_WAS_RUNNING" = false ] && [ "${KEEP_ALERT_DRILL_STACK:-false}" != true ]; then
        docker compose -f "$COMPOSE_FILE" down
    fi
}

trap cleanup EXIT

if [ -n "$(docker compose -f "$COMPOSE_FILE" ps --status running -q)" ]; then
    STACK_WAS_RUNNING=true
fi

if [ ! -f "$ROOT_DIR/.env" ]; then
    cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
fi

docker compose -f "$COMPOSE_FILE" up -d --build postgres redis rabbitmq elasticsearch alert-webhook alertmanager
docker compose -f "$COMPOSE_FILE" build php
docker compose -f "$COMPOSE_FILE" run --rm php composer install --no-interaction --prefer-dist --no-progress

if grep -q '^APP_KEY=$' "$ROOT_DIR/.env"; then
    docker compose -f "$COMPOSE_FILE" run --rm php php artisan key:generate --force
fi

docker compose -f "$COMPOSE_FILE" up -d --build php domain-event-worker prometheus
docker compose -f "$COMPOSE_FILE" exec -T php php artisan migrate --force

wait_for_url "Market gateway" "$MARKET_URL/health/live"
wait_for_url "Prometheus" "$PROMETHEUS_URL/-/ready"
wait_for_url "Alert receiver" "$ALERTS_URL/health"
wait_for_rabbitmq

printf 'Проверка роста DLQ\n'
reset_alerts
rabbitmq_api PUT "queues/%2F/$DLQ_NAME" '{"durable":false,"auto_delete":false,"arguments":{}}'
wait_for_prometheus_series "rabbitmq_queue_messages_ready{queue=\"$DLQ_NAME\"}"
rabbitmq_api POST 'exchanges/%2F/amq.default/publish' "{\"properties\":{},\"routing_key\":\"$DLQ_NAME\",\"payload\":\"drill\",\"payload_encoding\":\"string\"}"
wait_for_alert_event StockflowDeadLetterGrowth firing
rabbitmq_api DELETE "queues/%2F/$DLQ_NAME/contents"
wait_for_alert_event StockflowDeadLetterGrowth resolved
rabbitmq_api DELETE "queues/%2F/$DLQ_NAME"

printf 'Проверка остановки consumer\n'
reset_alerts
wait_for_prometheus_series 'rabbitmq_queue_consumers{queue="stockflow.market.domain.events"}'
docker compose -f "$COMPOSE_FILE" stop domain-event-worker
wait_for_alert_event StockflowConsumerDown firing
docker compose -f "$COMPOSE_FILE" start domain-event-worker
wait_for_alert_event StockflowConsumerDown resolved

printf 'Проверка недоступности RabbitMQ\n'
reset_alerts
docker compose -f "$COMPOSE_FILE" stop rabbitmq
wait_for_alert_event StockflowRabbitMqUnavailable firing
docker compose -f "$COMPOSE_FILE" start rabbitmq
wait_for_rabbitmq
docker compose -f "$COMPOSE_FILE" up -d domain-event-worker
wait_for_alert_event StockflowRabbitMqUnavailable resolved

printf 'Проверка высокой HTTP latency\n'
reset_alerts
start_latency
wait_for_alert_event StockflowHttpLatencyP95High firing
stop_latency
wait_for_alert_event StockflowHttpLatencyP95High resolved

printf 'Все аварийные сценарии доставили firing и resolved уведомления.\n'
