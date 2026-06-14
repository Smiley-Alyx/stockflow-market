# Провайдеры AI-ассистента

Левый AI-ассистент не зависит от конкретной модели. Контроллер вызывает
`AssistantProviderManager`, который выбирает адаптер по `AI_ASSISTANT_PROVIDER`.

## Выбор провайдера

OpenAI используется по умолчанию:

```dotenv
AI_ASSISTANT_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-mini
```

Заготовки для других адаптеров:

```dotenv
AI_ASSISTANT_PROVIDER=gigachat
GIGACHAT_ASSISTANT_DRIVER=App\\Domains\\Assistant\\Services\\GigaChatShoppingAssistant
```

или:

```dotenv
AI_ASSISTANT_PROVIDER=yandex
YANDEX_ASSISTANT_DRIVER=App\\Domains\\Assistant\\Services\\YandexShoppingAssistant
```

После изменения `.env` необходимо перезапустить backend:

```bash
docker compose restart php
```

## Контракт адаптера

Новый адаптер должен реализовать:

```php
App\Domains\Assistant\Contracts\ShoppingAssistantProvider
```

Метод `respond()` возвращает общий для всех провайдеров формат:

```php
[
    'message' => 'Ответ пользователю',
    'conversation_id' => 'идентификатор диалога или null',
    'products' => [],
]
```

Для поиска актуальных товаров адаптер должен использовать
`App\Domains\Assistant\Tools\CatalogSearchTool`. Инструмент предоставляет:

- `execute()` — поиск по полному каталогу с ценой, наличием, цветом и сортировкой;
- `context()` — компактный безопасный контекст товаров для модели;
- `jsonSchema()` — общую JSON-схему аргументов инструмента.

OpenAI-адаптер запрашивает структурированный финальный ответ с `message` и
`product_ids`. Перед возвратом клиенту он пересекает `product_ids` с товарами,
которые реально были получены через `search_catalog`. Поэтому карточки из
предварительных поисков и несуществующие ID не попадают в ответ ассистента.

API провайдера, авторизация и преобразование его tool-calling формата остаются
внутри адаптера. Контроллер, frontend и поиск каталога при смене провайдера не
изменяются.
