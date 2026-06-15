#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker/backup-restore/compose.yml"
PROJECT=stockflow-backup-drill
BACKUP_DIR=${TMPDIR:-/tmp}/stockflow-backup-restore.$$
RABBITMQ_VOLUME=stockflow-backup-drill-rabbitmq-data
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-120}
MARKER=stockflow-backup-restore-marker

compose() {
    docker compose -p "$PROJECT" -f "$COMPOSE_FILE" "$@"
}

wait_for_service() {
    service=$1
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        status=$(compose ps --format json "$service" 2>/dev/null |
            php -r '
                $input = trim(stream_get_contents(STDIN));
                if ($input === "") {
                    exit;
                }
                $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                $service = array_is_list($decoded) ? ($decoded[0] ?? []) : $decoded;
                echo $service["Health"] ?? $service["State"] ?? "";
            ')

        if [ "$status" = healthy ]; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf '%s: service did not become healthy\n' "$service" >&2
    exit 1
}

postgres_query() {
    compose exec -T postgres psql -v ON_ERROR_STOP=1 -U backup_drill -d stockflow "$@"
}

clickhouse_query() {
    compose exec -T clickhouse clickhouse-client \
        --user backup_drill \
        --password backup_drill \
        --database stockflow \
        "$@"
}

rabbitmq_admin() {
    compose exec -T rabbitmq rabbitmqadmin \
        --username backup_drill \
        --password backup_drill \
        "$@"
}

wait_for_postgres() {
    wait_for_client postgres postgres_query -Atqc 'SELECT 1;'
}

wait_for_clickhouse() {
    wait_for_client clickhouse clickhouse_query --query 'SELECT 1'
}

wait_for_rabbitmq() {
    wait_for_client rabbitmq rabbitmq_admin show overview
}

wait_for_client() {
    name=$1
    shift
    attempts=$TIMEOUT_SECONDS

    while [ "$attempts" -gt 0 ]; do
        if "$@" >/dev/null 2>&1; then
            return
        fi

        attempts=$((attempts - 1))
        sleep 1
    done

    printf '%s: client protocol did not become ready\n' "$name" >&2
    exit 1
}

cleanup() {
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$BACKUP_DIR"
}

trap cleanup EXIT

mkdir -p "$BACKUP_DIR"
compose down --volumes --remove-orphans >/dev/null 2>&1 || true
compose up -d
wait_for_service postgres
wait_for_service rabbitmq
wait_for_service clickhouse
wait_for_postgres
wait_for_rabbitmq
wait_for_clickhouse

printf 'Проверка восстановления PostgreSQL\n'
postgres_query -c 'CREATE TABLE backup_restore_drill (marker text PRIMARY KEY);'
postgres_query -c "INSERT INTO backup_restore_drill VALUES ('$MARKER');"
compose exec -T postgres pg_dump -U backup_drill -d stockflow --format=custom > "$BACKUP_DIR/postgres.dump"
postgres_query -c 'DROP TABLE backup_restore_drill;'
compose exec -T postgres pg_restore -U backup_drill -d stockflow --exit-on-error < "$BACKUP_DIR/postgres.dump"
postgres_query -Atqc "SELECT marker FROM backup_restore_drill;" | grep -Fx "$MARKER" >/dev/null

printf 'Проверка восстановления ClickHouse\n'
clickhouse_query --query 'CREATE TABLE backup_restore_drill (marker String) ENGINE = MergeTree ORDER BY marker'
clickhouse_query --query "INSERT INTO backup_restore_drill VALUES ('$MARKER')"
clickhouse_query --query 'SELECT * FROM backup_restore_drill FORMAT Native' > "$BACKUP_DIR/clickhouse.native"
clickhouse_query --query 'DROP TABLE backup_restore_drill'
clickhouse_query --query 'CREATE TABLE backup_restore_drill (marker String) ENGINE = MergeTree ORDER BY marker'
clickhouse_query --query 'INSERT INTO backup_restore_drill FORMAT Native' < "$BACKUP_DIR/clickhouse.native"
clickhouse_query --query 'SELECT marker FROM backup_restore_drill FORMAT TSVRaw' | grep -Fx "$MARKER" >/dev/null

printf 'Проверка восстановления RabbitMQ\n'
rabbitmq_admin declare queue --name backup.restore.drill --durable true >/dev/null
rabbitmq_admin publish message --exchange amq.default --routing-key backup.restore.drill --payload "$MARKER" --properties '{"delivery_mode":2}' >/dev/null
compose exec -T rabbitmq rabbitmqctl list_queues name messages_ready |
    grep -E 'backup\.restore\.drill[[:space:]]+1' >/dev/null
compose stop rabbitmq >/dev/null
docker run --rm \
    -v "$RABBITMQ_VOLUME:/source:ro" \
    -v "$BACKUP_DIR:/backup" \
    alpine:3.22 \
    tar czf /backup/rabbitmq.tgz -C /source .
compose rm -f rabbitmq >/dev/null
docker volume rm "$RABBITMQ_VOLUME" >/dev/null
docker volume create "$RABBITMQ_VOLUME" >/dev/null
docker run --rm \
    -v "$RABBITMQ_VOLUME:/target" \
    -v "$BACKUP_DIR:/backup:ro" \
    alpine:3.22 \
    tar xzf /backup/rabbitmq.tgz -C /target
compose up -d rabbitmq
wait_for_service rabbitmq
wait_for_rabbitmq
compose exec -T rabbitmq rabbitmqctl list_queues name messages_ready |
    grep -E 'backup\.restore\.drill[[:space:]]+1' >/dev/null
rabbitmq_admin get messages --queue backup.restore.drill --count 1 --ack-mode ack_requeue_false | grep -F "$MARKER" >/dev/null

printf 'PostgreSQL, RabbitMQ и ClickHouse восстановлены из резервных копий.\n'
