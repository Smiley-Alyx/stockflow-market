# RabbitMQ transport доменных событий

## Поток сообщения

При `STOCKFLOW_EVENT_BUS=rabbitmq` команда `messaging:outbox:publish` больше не
вызывает Laravel listeners внутри процесса. Relay публикует persistent-сообщение
в topic exchange `stockflow.domain.events` и помечает outbox-запись
опубликованной только после publisher confirm.

`domain-event-worker` читает очередь `stockflow.market.domain.events` и
диспатчит событие локальным consumers. JSON envelope содержит `event_name`,
`schema_version`, агрегат и публичный `payload`. Laravel runtime восстанавливает
только явно зарегистрированные типы событий из JSON-контракта и отклоняет
неизвестные события или версии до запуска listeners.

Producer объявляет только durable topic exchange. Consumer отдельно объявляет
свои основную, retry и dead-letter очереди и bindings, поэтому добавление нового
межсервисного consumer не требует менять producer.

Машиночитаемый формат envelope зафиксирован в
`services/gateway/contracts/domain-event-envelope.v1.schema.json`.
Payload каждого зарегистрированного события описан отдельной JSON Schema в
`services/gateway/contracts/domain-events/v1/`. Manifest
`services/gateway/contracts/domain-events/manifest.json` связывает имя события,
версию и файл схемы.

Пример envelope:

```json
{
  "event_name": "search.index.requested",
  "schema_version": 1,
  "aggregate_type": "search_document",
  "aggregate_id": "catalog_products:15",
  "payload": {
    "event": "search.index.requested",
    "index": "catalog_products",
    "document_id": "15",
    "document": {
      "sku": "SCAN-001"
    }
  }
}
```

## Гарантии повторной доставки

- delivery семантика transport — at-least-once;
- `message_id` стабилен для outbox-записи: `<service>:domain-outbox:<id>`;
- общий inbox дедуплицирует повторную доставку до запуска listeners;
- неизвестная версия контракта считается ошибкой обработки и проходит обычный
  retry/DLQ-путь;
- ошибка consumer отправляет сообщение в TTL retry queue;
- после `STOCKFLOW_DOMAIN_EVENTS_MAX_RETRY_COUNT` сообщение попадает в
  `stockflow.market.domain.events.dlq`;
- retry и DLQ публикации ожидают publisher confirm до ack исходной доставки;
- если публикация в retry exchange не удалась, исходное сообщение возвращается
  в основную очередь через `nack(requeue=true)`.

Сценарии покрыты `DomainEventConsumerTest`: повторная доставка создаёт side
effect один раз, временная ошибка отправляет сообщение в retry queue,
исчерпанные попытки отправляют сообщение в DLQ, а ошибка retry-публикации
возвращает исходное сообщение в очередь.

Broker-level сценарий публикует одно валидное событие дважды с одинаковым
`message_id` и проверяет однократную обработку через inbox. Затем он публикует
несовместимую версию события и проверяет TTL retry, итоговый `retry_count` и
маршрутизацию в DLQ:

```bash
./scripts/test-domain-event-redelivery-e2e.sh
```

Сценарий требует уже запущенные `postgres`, `rabbitmq` и
`domain-event-worker`. Полный `test-broker-checkout-e2e.sh` запускает его
автоматически после provider saga.

## Эволюция контрактов

Producer и consumer используют один `DomainEventSchemaRegistry`. Producer
проверяет результат `payload()` перед записью в outbox, consumer проверяет
полученный payload до восстановления и dispatch события.

`DomainEventContractTest` выполняет контрактные проверки обеих сторон:

- каждый зарегистрированный тип события обязан иметь схему в manifest;
- реальные producer payload должны соответствовать своим схемам;
- consumer должен отклонять payload без обязательных полей;
- consumer должен восстанавливать валидные stateless-события.

Схемы текущей версии допускают новые необязательные поля через
`additionalProperties: true`. Для несовместимого изменения нужно выпустить
новую версию схемы и сохранить поддержку старой версии на время миграции
consumers.

В pull request CI запускает:

```bash
php scripts/check-event-contract-compatibility.php <base-git-ref>
```

Проверка останавливает сборку при удалении события, добавлении обязательного
поля, сужении допустимого типа или enum, смене `const`, ужесточении числовых и
размерных ограничений и запрете ранее допустимых дополнительных полей.

Локально текущую ветку можно сравнить с основной:

```bash
php scripts/check-event-contract-compatibility.php origin/main
```

## Проверка очередей

```bash
docker compose -f docker-compose-all.yml exec -T rabbitmq \
  rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers |
  grep 'stockflow.market.domain.events'
```

```bash
docker compose -f docker-compose-all.yml logs --since=10m \
  domain-outbox-worker domain-event-worker
```

## Конфигурация

| Переменная | Значение по умолчанию |
| --- | --- |
| `STOCKFLOW_DOMAIN_EVENTS_EXCHANGE` | `stockflow.domain.events` |
| `STOCKFLOW_DOMAIN_EVENTS_QUEUE` | `stockflow.market.domain.events` |
| `STOCKFLOW_DOMAIN_EVENTS_RETRY_QUEUE` | `stockflow.market.domain.events.retry` |
| `STOCKFLOW_DOMAIN_EVENTS_DLQ` | `stockflow.market.domain.events.dlq` |
| `STOCKFLOW_DOMAIN_EVENTS_RETRY_DELAY_MS` | `2000` |
| `STOCKFLOW_DOMAIN_EVENTS_MAX_RETRY_COUNT` | `3` |
| `STOCKFLOW_DOMAIN_EVENTS_CONFIRM_TIMEOUT_SECONDS` | `5` |
