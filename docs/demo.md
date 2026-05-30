# Демонстрация поискового контура

Этот сценарий показывает работающий backend-поток:

```text
создать товар → записать событие в outbox → поставить индексацию в очередь
→ найти товар через Elasticsearch → получить dead-letter при сбое → выполнить requeue
```

RabbitMQ для этой демонстрации не нужен: текущий publisher обрабатывает transactional outbox in-process. Очередь поисковой индексации работает через Redis, а документы сохраняются в Elasticsearch.

## Подготовка

Запустить базовый локальный стек, установить зависимости и применить миграции:

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

Убедиться, что backend, Redis, Elasticsearch и worker индексации запущены:

```bash
docker compose ps
```

## Создание товара

Создать или обновить демонстрационный товар через Eloquent. Изменение `description` делает команду повторяемой: при повторном запуске будет создано новое событие обновления.

```bash
docker compose exec php php artisan tinker --execute='
$category = App\Domains\Catalog\Models\Category::query()->firstOrCreate(
    ["slug" => "demo-devices"],
    ["name" => "Demo devices", "is_active" => true],
);

$product = App\Domains\Catalog\Models\Product::query()->updateOrCreate(
    ["slug" => "demo-wireless-scanner"],
    [
        "category_id" => $category->id,
        "name" => "Demo Wireless Scanner",
        "sku" => "DEMO-SCAN-001",
        "description" => "Demo run ".now()->toIso8601String(),
        "status" => "published",
        "published_at" => now(),
    ],
);

dump(["product_id" => $product->id, "slug" => $product->slug]);
'
```

Модель товара отправит `catalog.product.created` или `catalog.product.updated`. Listener преобразует его в `search.index.requested` и сохранит событие в таблице transactional outbox.

Посмотреть ожидающее событие:

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select id, event_name, status, attempts from messaging_outbox order by id desc limit 5;"
```

## Индексация и поиск

Опубликовать ожидающие outbox-события:

```bash
docker compose exec php php artisan messaging:outbox:publish
```

Команда отправит `search.index.requested` во внутренний event dispatcher. Listener поставит `IndexSearchDocument` в Redis-очередь `search-indexing`, а `search-index-worker` сохранит документ в Elasticsearch.

Посмотреть логи worker:

```bash
docker compose logs --tail=50 search-index-worker
```

Найти товар через публичный API:

```bash
curl -sS "http://localhost:8080/api/search/products?q=Demo%20Wireless%20Scanner"
```

В `data` должен появиться товар со `slug` `demo-wireless-scanner`.

## Dead-letter и requeue

Остановить Elasticsearch:

```bash
docker compose stop elasticsearch
```

Обновить товар, чтобы создать новое событие индексации:

```bash
docker compose exec php php artisan tinker --execute='
$product = App\Domains\Catalog\Models\Product::query()
    ->where("slug", "demo-wireless-scanner")
    ->firstOrFail();

$product->update([
    "name" => "Demo Scanner Requeued",
    "description" => "Elasticsearch failure demo ".now()->toIso8601String(),
]);
'
```

Опубликовать outbox-событие:

```bash
docker compose exec php php artisan messaging:outbox:publish
```

Worker выполнит несколько попыток индексации. После исчерпания retry документ попадёт в Redis-backed dead-letter storage. Дождаться ошибки можно по логам:

```bash
docker compose logs -f search-index-worker
```

Остановить просмотр логов сочетанием `Ctrl+C`, затем вывести dead-letter записи:

```bash
docker compose exec php php artisan search:dead-letter list --index=catalog_products
```

Запомнить `ID` строки демонстрационного документа, восстановить Elasticsearch и дождаться закрытия circuit breaker:

```bash
docker compose start elasticsearch
sleep 35
```

Вернуть запись в очередь, подставив её `ID`:

```bash
docker compose exec php php artisan search:dead-letter requeue --id=<ID>
```

Проверить, что товар снова находится:

```bash
curl -sS "http://localhost:8080/api/search/products?q=Demo%20Scanner%20Requeued"
```

## Что демонстрирует сценарий

- события каталога не индексируют товар напрямую, а записывают `search.index.requested` в transactional outbox;
- publisher отделён от создания товара и запускается командой `messaging:outbox:publish`;
- Redis-очередь отделяет публикацию события от записи в Elasticsearch;
- Elasticsearch обслуживает отдельный поисковый read endpoint;
- retry и dead-letter сохраняют неиндексированный документ при сбое;
- `search:dead-letter requeue` возвращает документ в штатную очередь после восстановления зависимости.
