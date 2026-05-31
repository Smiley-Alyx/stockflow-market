#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose-all.yml"

wait_for_url() {
    name=$1
    url=$2
    attempts=30

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS "$url" >/dev/null 2>&1; then
            printf '%s: доступен (%s)\n' "$name" "$url"
            return 0
        fi

        attempts=$((attempts - 1))
        sleep 2
    done

    printf '%s: endpoint не ответил вовремя (%s)\n' "$name" "$url" >&2
    return 1
}

assert_service_running() {
    service=$1

    if [ -z "$(docker compose -f "$COMPOSE_FILE" ps --status running -q "$service")" ]; then
        printf '%s: контейнер не запущен\n' "$service" >&2
        return 1
    fi

    printf '%s: контейнер запущен\n' "$service"
}

if [ ! -f "$ROOT_DIR/.env" ]; then
    cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
fi

docker compose -f "$COMPOSE_FILE" up -d --build postgres redis rabbitmq elasticsearch
docker compose -f "$COMPOSE_FILE" build php
docker compose -f "$COMPOSE_FILE" run --rm php composer install

if grep -q '^APP_KEY=$' "$ROOT_DIR/.env"; then
    docker compose -f "$COMPOSE_FILE" run --rm php php artisan key:generate --force
fi

docker compose -f "$COMPOSE_FILE" up -d --build
docker compose -f "$COMPOSE_FILE" exec -T php php artisan migrate --force

wait_for_url "Market gateway" "http://localhost:8080/health/ready"
wait_for_url "Payment mock" "http://localhost:8081/health"
wait_for_url "Delivery mock" "http://localhost:8082/health"
wait_for_url "ERP mock" "http://localhost:8083/health"

for service in queue-worker search-index-worker scheduler domain-outbox-worker provider-outbox-worker provider-outcome-worker payment-mock-worker delivery-mock-worker; do
    assert_service_running "$service"
done

cat <<'EOF'

Стенд готов.

Market gateway: http://localhost:8080
Payment mock:   http://localhost:8081
Delivery mock:  http://localhost:8082
ERP mock:       http://localhost:8083
RabbitMQ UI:    http://localhost:15672 (stockflow / stockflow)

План пятиминутного показа: docs/demo.md
EOF
