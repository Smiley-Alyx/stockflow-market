#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose-all.yml"
STACK_WAS_RUNNING=false

wait_for_url() {
    name=$1
    url=$2
    attempts=30

    while [ "$attempts" -gt 0 ]; do
        if curl -fsS "$url" >/dev/null 2>&1; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 2
    done

    printf '%s: endpoint не ответил вовремя (%s)\n' "$name" "$url" >&2
    exit 1
}

cleanup() {
    if [ "$STACK_WAS_RUNNING" = false ] && [ "${KEEP_BROKER_E2E_STACK:-false}" != true ]; then
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

docker compose -f "$COMPOSE_FILE" up -d --build postgres redis rabbitmq elasticsearch
docker compose -f "$COMPOSE_FILE" build php
docker compose -f "$COMPOSE_FILE" run --rm php composer install --no-interaction

if grep -q '^APP_KEY=$' "$ROOT_DIR/.env"; then
    docker compose -f "$COMPOSE_FILE" run --rm php php artisan key:generate --force
fi

docker compose -f "$COMPOSE_FILE" run --rm rabbitmq-topology
"$ROOT_DIR/scripts/test-rabbitmq-runtime-permissions.sh"
docker compose -f "$COMPOSE_FILE" up -d --build php erp-mock payment-mock delivery-mock
docker compose -f "$COMPOSE_FILE" exec -T php php artisan migrate --force
docker compose -f "$COMPOSE_FILE" up -d --build \
    domain-outbox-worker \
    domain-event-worker \
    provider-outbox-worker \
    provider-outcome-worker \
    payment-mock-worker \
    delivery-mock-worker

wait_for_url "Market gateway" "http://localhost:8080/health/ready"
wait_for_url "Payment sandbox" "http://localhost:8081/health"
wait_for_url "Delivery sandbox" "http://localhost:8082/health"
wait_for_url "ERP sandbox" "http://localhost:8083/health"

"$ROOT_DIR/scripts/test-provider-saga-e2e.sh"
"$ROOT_DIR/scripts/test-domain-event-redelivery-e2e.sh"
