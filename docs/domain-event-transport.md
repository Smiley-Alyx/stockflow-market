# RabbitMQ transport доменных событий

## Поток сообщения

При `STOCKFLOW_EVENT_BUS=rabbitmq` команда `messaging:outbox:publish` больше не
вызывает Laravel listeners внутри процесса. Relay публикует persistent-сообщение
в topic exchange `stockflow.domain.events` и помечает outbox-запись
опубликованной только после publisher confirm.

`domain-event-worker` читает очередь `stockflow.market.domain.events` и
диспатчит событие локальным consumers. JSON envelope содержит публичный
`payload` и служебное `serialized_event`, необходимое переходному Laravel
runtime для восстановления объекта события.

## Гарантии повторной доставки

- delivery семантика transport — at-least-once;
- `message_id` стабилен для outbox-записи: `<service>:domain-outbox:<id>`;
- общий inbox дедуплицирует повторную доставку до запуска listeners;
- ошибка consumer отправляет сообщение в TTL retry queue;
- после `STOCKFLOW_DOMAIN_EVENTS_MAX_RETRY_COUNT` сообщение попадает в
  `stockflow.market.domain.events.dlq`;
- если публикация в retry exchange не удалась, исходное сообщение возвращается
  в основную очередь через `nack(requeue=true)`.

Сценарии покрыты `DomainEventConsumerTest`: повторная доставка создаёт side
effect один раз, временная ошибка отправляет сообщение в retry queue,
исчерпанные попытки отправляют сообщение в DLQ, а ошибка retry-публикации
возвращает исходное сообщение в очередь.

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
