# Резервное копирование и восстановление

Резервная копия считается пригодной только после успешного восстановления и
проверки контрольных данных. Наличие backup-файла без restore drill не является
подтверждением восстановления.

## Общие требования

- Backup выполняется отдельным техническим пользователем с правами только на
  чтение данных и необходимые backup-операции.
- Backup шифруется до отправки в удалённое хранилище. Ключ шифрования хранится
  отдельно от backup.
- Хранилище включает versioning, retention policy и immutable/WORM-копию.
- Для каждого backup сохраняются timestamp, версия сервиса, checksum, размер и
  идентификатор encryption key.
- Restore всегда сначала выполняется в изолированном окружении без доступа
  production-клиентов.
- После восстановления проверяются schema, количество ключевых записей,
  контрольные данные и прикладной smoke test.
- RPO и RTO задаются отдельно для каждого production-окружения и регулярно
  сверяются с фактическим временем drill.

## PostgreSQL

Автоматический drill использует логический `pg_dump --format=custom` и
`pg_restore --exit-on-error`. Для production рекомендуется сочетать:

- регулярный base backup;
- непрерывный архив WAL для point-in-time recovery;
- периодический logical dump для переносимости и выборочного восстановления.

Порядок восстановления:

1. Проверить checksum и расшифровать backup во временное хранилище.
2. Поднять PostgreSQL той же major-версии в изолированной сети.
3. Восстановить base backup и WAL до требуемой точки либо выполнить
   `pg_restore`.
4. Выполнить migrations/schema check, проверить критические таблицы и
   консистентность outbox/inbox.
5. Запустить read-only smoke test приложения.

## RabbitMQ

RabbitMQ definitions содержат exchanges, queues, bindings, users и permissions,
но **не содержат сообщения**. Экспорт definitions не является достаточным
backup для durable backlog.

Автоматический drill создаёт durable queue и persistent message, останавливает
broker, делает cold snapshot data volume, создаёт пустой volume и подтверждает,
что после восстановления доступны queue и сообщение.

Для production:

- сохранять definitions отдельно для быстрого восстановления topology;
- делать согласованный snapshot data volumes только после остановки node либо
  по процедуре, поддерживаемой используемым storage;
- сохранять Erlang cookie, hostname/node-name и точную версию RabbitMQ;
- для cluster/quorum queues использовать документированную процедуру
  восстановления кластера, а не копирование volume одного произвольного node.

После восстановления проверяются permissions, bindings, количество сообщений,
consumer connectivity, publisher confirms и обработка retry/DLQ.

## ClickHouse

Автоматический drill использует переносимый логический backup в формате
`Native`: удаляет исходную таблицу, создаёт schema заново и загружает данные.

Для production следует использовать штатный `BACKUP`/`RESTORE` на настроенный
backup disk или проверенный инструмент, который сохраняет metadata и data parts
в object storage. Для replicated tables процедура должна учитывать Keeper и
репликацию.

После восстановления проверяются metadata таблиц, число строк, контрольные
агрегации и прикладные аналитические запросы.

## Автоматический restore drill

Запуск:

```bash
./scripts/test-backup-restore.sh
```

Скрипт использует отдельный compose-файл
`docker/backup-restore/compose.yml`, отдельные volumes и временную директорию.
Он не обращается к локальному основному стенду и удаляет drill-ресурсы после
завершения.

Проверяемые сценарии:

| Сервис | Backup | Проверка восстановления |
| --- | --- | --- |
| PostgreSQL | Custom-format logical dump | Таблица удалена, восстановлена, контрольная запись прочитана |
| RabbitMQ | Cold snapshot data volume | Broker и volume пересозданы, durable queue и persistent message доступны |
| ClickHouse | Logical `Native` stream | Таблица удалена, schema создана заново, контрольная запись загружена |

CI job `backup-restore-drill` запускается вручную, по расписанию и после push в
`main`. При ошибке CI сохраняет логи изолированного стенда.

Drill подтверждает работоспособность команд и совместимость текущих форматов
backup. Он не заменяет production drill на реальном объёме данных и не
подтверждает целевые RPO/RTO до отдельного измерения.
