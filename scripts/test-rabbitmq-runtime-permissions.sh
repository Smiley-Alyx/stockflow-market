#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE=${COMPOSE_FILE:-"$ROOT_DIR/docker-compose-all.yml"}
RABBITMQ_API=${RABBITMQ_API:-http://localhost:15672/api}
RUNTIME_USER=${STOCKFLOW_RABBITMQ_RUNTIME_USER:-stockflow-market-runtime}

permission=$(curl -fsS -u stockflow:stockflow "$RABBITMQ_API/permissions/%2F/$RUNTIME_USER")

if ! printf '%s' "$permission" | jq -e '.configure == "^$"' >/dev/null; then
    printf 'Runtime RabbitMQ user получил configure permission\n' >&2
    exit 1
fi

if docker compose -f "$COMPOSE_FILE" run --rm --no-deps php \
    php artisan messaging:rabbitmq:provision >/dev/null 2>&1; then
    printf 'Runtime RabbitMQ user смог изменить topology\n' >&2
    exit 1
fi

printf 'Runtime RabbitMQ user не имеет configure permission.\n'
