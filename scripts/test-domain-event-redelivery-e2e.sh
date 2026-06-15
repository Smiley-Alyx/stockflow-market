#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE=${COMPOSE_FILE:-"$ROOT_DIR/docker-compose-all.yml"}
RABBITMQ_API=${RABBITMQ_API:-http://localhost:15672/api}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-30}
QUEUE_NAME=${STOCKFLOW_DOMAIN_EVENTS_QUEUE:-stockflow.market.domain.events}
DLQ_NAME=${STOCKFLOW_DOMAIN_EVENTS_DLQ:-stockflow.market.domain.events.dlq}
EXCHANGE_NAME=${STOCKFLOW_DOMAIN_EVENTS_EXCHANGE:-stockflow.domain.events}
RUN_ID="$(date +%s)-$$"
VALID_MESSAGE_ID="redelivery-drill:valid:$RUN_ID"
FAILED_MESSAGE_ID="redelivery-drill:failed:$RUN_ID"

rabbitmq_api() {
    method=$1
    path=$2
    body=${3:-}

    if [ -n "$body" ]; then
        curl -fsS -u stockflow:stockflow -X "$method" "$RABBITMQ_API/$path" \
            -H 'content-type: application/json' \
            --data "$body"
    else
        curl -fsS -u stockflow:stockflow -X "$method" "$RABBITMQ_API/$path"
    fi
}

sql() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U stockflow -d stockflow -Atc "$1"
}

wait_for_sql_count() {
    message_id=$1
    status=$2
    expected=$3
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        count=$(sql "select count(*) from messaging_inbox where message_id = '$message_id' and status = '$status'")
        if [ "$count" -eq "$expected" ]; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf 'Inbox не достиг ожидаемого состояния: message_id=%s status=%s count=%s\n' \
        "$message_id" "$status" "$expected" >&2
    exit 1
}

wait_for_dlq_message() {
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        message=$(rabbitmq_api POST "queues/%2F/$DLQ_NAME/get" \
            '{"count":1,"ackmode":"ack_requeue_true","encoding":"auto","truncate":50000}')
        if printf '%s' "$message" | jq -e \
            --arg message_id "$FAILED_MESSAGE_ID" \
            '.[0].properties.headers.message_id == $message_id
                and .[0].properties.headers.retry_count == 4' >/dev/null; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf 'Сообщение не попало в DLQ %s\n' "$DLQ_NAME" >&2
    exit 1
}

publish_event() {
    message_id=$1
    schema_version=$2
    document_id=$3
    payload=$(jq -cn \
        --arg document_id "$document_id" \
        --argjson schema_version "$schema_version" \
        '{
            event_name: "search.index.requested",
            schema_version: $schema_version,
            aggregate_type: "search_document",
            aggregate_id: $document_id,
            payload: {
                event: "search.index.requested",
                index: "catalog_products",
                document_id: $document_id,
                document: {id: $document_id, name: "RabbitMQ redelivery drill"}
            }
        }')
    request=$(jq -cn \
        --arg payload "$payload" \
        --arg message_id "$message_id" \
        --argjson schema_version "$schema_version" \
        '{
            properties: {
                delivery_mode: 2,
                headers: {
                    message_id: $message_id,
                    event_name: "search.index.requested",
                    schema_version: $schema_version,
                    producer: "redelivery-drill",
                    retry_count: 0
                }
            },
            routing_key: "search.index.requested",
            payload: $payload,
            payload_encoding: "string"
        }')

    rabbitmq_api POST "exchanges/%2F/$EXCHANGE_NAME/publish" "$request" |
        jq -e '.routed == true' >/dev/null
}

cleanup() {
    sql "delete from messaging_inbox where message_id in ('$VALID_MESSAGE_ID', '$FAILED_MESSAGE_ID')" >/dev/null 2>&1 || true
    rabbitmq_api DELETE "queues/%2F/$DLQ_NAME/contents" >/dev/null 2>&1 || true
}

trap cleanup EXIT

printf 'Проверка дедупликации повторной доставки\n'
publish_event "$VALID_MESSAGE_ID" 1 "$RUN_ID"
publish_event "$VALID_MESSAGE_ID" 1 "$RUN_ID"
wait_for_sql_count "$VALID_MESSAGE_ID" processed 2
sleep 2
wait_for_sql_count "$VALID_MESSAGE_ID" processed 2

printf 'Проверка retry и DLQ для несовместимой версии\n'
rabbitmq_api DELETE "queues/%2F/$DLQ_NAME/contents" >/dev/null
publish_event "$FAILED_MESSAGE_ID" 999 "$RUN_ID"
wait_for_dlq_message
wait_for_sql_count "$FAILED_MESSAGE_ID" failed 1

printf 'Повторная доставка дедуплицирована, retry и DLQ проверены.\n'
