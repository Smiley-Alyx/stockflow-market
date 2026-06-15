# Эксплуатационная безопасность

Этот документ описывает production-процедуры. Значения `stockflow`, `secret` и
другие статические креды из локальных compose-файлов предназначены только для
локального стенда и не должны использоваться вне него.

## Управление секретами

Production-секреты хранятся в secret manager и передаются workload как
временные файлы или переменные окружения на этапе запуска. Секреты не
записываются в Docker image, Git, SBOM, логи, backup-архивы без шифрования и
диагностические артефакты CI.

Минимальный инвентарь:

| Секрет | Потребители | Ротация |
| --- | --- | --- |
| `APP_KEY` | Laravel runtime и workers | По инциденту или планово с миграцией зашифрованных данных |
| PostgreSQL password | Runtime, workers, backup job | Не реже 90 дней |
| RabbitMQ passwords | Publishers, consumers, monitoring | Не реже 90 дней |
| ClickHouse password | Runtime, analytics jobs, backup job | Не реже 90 дней |
| `OPENAI_API_KEY` и ключи других AI-провайдеров | Только assistant adapter | Не реже 90 дней и сразу после утечки |
| `STOCKFLOW_PROVIDER_SAGA_PAYMENT_TOKEN` | Checkout saga | По правилам provider boundary |
| Backup encryption key | Backup и restore jobs | Отдельно от backup-хранилища, не реже года |

Для обычного секрета используется двухфазная ротация:

1. Создать новый credential с теми же минимальными правами.
2. Обновить secret manager и перезапустить одну canary-реплику.
3. Проверить readiness, ошибки авторизации, публикацию и потребление сообщений.
4. Постепенно перезапустить остальные workloads.
5. Убедиться, что старый credential больше не используется.
6. Отозвать старый credential и зафиксировать ротацию в журнале изменений.

Нельзя сначала отзывать старый credential: это создаёт одновременный отказ всех
реплик и может остановить consumers.

### Ротация APP_KEY

Смена `APP_KEY` инвалидирует cookies, сессии и данные, зашифрованные Laravel.
Перед ротацией нужно найти все сохранённые encrypted values, расшифровать их
старым ключом и перешифровать новым в контролируемом maintenance window. После
переключения необходимо завершить старые сессии и проверить authentication,
очереди и background jobs. Старый ключ хранится ограниченное время только для
rollback и затем уничтожается.

### Ротация PostgreSQL и ClickHouse

Для баз данных предпочтительна ротация через нового пользователя:

1. Создать пользователя с минимальными grants.
2. Переключить workloads и backup job.
3. Проверить запись, чтение и backup/restore drill.
4. Удалить старого пользователя.

Не следует менять password единственного общего пользователя на работающем
стенде: часть реплик неизбежно продолжит использовать старое значение.

### Ротация RabbitMQ

RabbitMQ credential ротируется через нового пользователя с теми же regex
permissions. После переключения publishers и consumers нужно проверить
publisher confirms, число consumers, retry/DLQ и отсутствие authentication
errors, затем удалить старого пользователя. Deployment job применяет пароль и
permissions из `STOCKFLOW_RABBITMQ_RUNTIME_USER` и
`STOCKFLOW_RABBITMQ_RUNTIME_PASSWORD`.

## Минимальные RabbitMQ permissions

Production использует отдельный vhost `/stockflow`. Приложение не использует
пользователя `guest` и не получает тег `administrator`.

Runtime market не объявляет exchanges, queues и bindings. Перед запуском
publishers и consumers отдельный deployment job выполняет
`php artisan messaging:rabbitmq:provision` под topology-admin credentials,
создаёт market topology и назначает runtime-пользователю `configure=^$`.

```bash
VHOST=/stockflow
RUNTIME_USER=stockflow-market-runtime

rabbitmqctl add_vhost "$VHOST"
rabbitmqctl set_permissions -p "$VHOST" "$RUNTIME_USER" \
  '^$' \
  '^(stockflow\.(domain\.events(\.(retry|dlx))?|inventory|payment|delivery|market\.provider\.outcomes\.(retry|retry-return|dlx)))$' \
  '^(stockflow\.market\.(domain\.events|provider\.outcomes)(\.(retry|dlq))?)$'
```

Порядок аргументов `set_permissions`: `configure`, `write`, `read`.

Рекомендуемые роли:

| Роль | Configure | Write | Read |
| --- | --- | --- | --- |
| `stockflow-market-runtime` | `^$` | Domain events, provider requests, retry/DLX | Только market domain-event и provider-outcome queues |
| `stockflow-provider-*` | Только собственные exchange/queues | Только собственные outcomes и retry/DLX | Только собственные request queues |
| `stockflow-monitoring` | `^$` | `^$` | `^$`; management tag `monitoring` |
| `stockflow-topology-admin` | Только provisioning job | Только provisioning job | Только provisioning job |

Monitoring user создаётся без доступа к payload:

```bash
rabbitmqctl add_user stockflow-monitoring "$MONITORING_PASSWORD"
rabbitmqctl set_user_tags stockflow-monitoring monitoring
rabbitmqctl set_permissions -p /stockflow stockflow-monitoring '^$' '^$' '^$'
```

После изменения permissions обязательны негативные проверки: runtime не должен
читать чужие queues, публиковать в произвольный exchange или создавать объект
вне разрешённого namespace. Локальная проверка выполняется командой:

```bash
./scripts/test-rabbitmq-runtime-permissions.sh
```

## Supply-chain проверки

CI job `supply-chain` выполняет:

- `composer backend-audit` по `composer.lock`, включая abandoned packages;
- CycloneDX SBOM исходников и собранного backend image через Syft;
- Trivy scan собранного image с блокировкой HIGH и CRITICAL уязвимостей.

SBOM загружается как CI artifact. Перед релизом он должен храниться рядом с
артефактом сборки и связываться с digest образа.

Требования к резервным копиям и автоматический restore drill описаны в
[`backup-restore.md`](backup-restore.md).
