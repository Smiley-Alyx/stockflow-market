# Демонстрация поискового контура

Этот сценарий показывает работающий backend-поток:

```text
создать товар → записать событие в outbox → поставить индексацию в очередь
→ найти товар через Elasticsearch → получить dead-letter при сбое → выполнить requeue
```

RabbitMQ для этой демонстрации не нужен: базовый `compose.yaml` использует
in-process fallback для transactional outbox. В общем стенде
`docker-compose-all.yml` те же доменные события проходят через RabbitMQ.
Очередь поисковой индексации работает через Redis, а документы сохраняются в
Elasticsearch.

## Подготовка

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
docker compose ps
```

## Создание товара

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

Модель товара отправит `catalog.product.created` или `catalog.product.updated`.
Listener преобразует его в `search.index.requested` и сохранит событие в
transactional outbox.

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select id, event_name, status, attempts from messaging_outbox order by id desc limit 5;"
```

## Индексация и поиск

```bash
docker compose exec php php artisan messaging:outbox:publish
docker compose logs --tail=50 search-index-worker
curl -sS "http://localhost:8080/api/search/products?q=Demo%20Wireless%20Scanner"
```

## Dead-letter и requeue

Остановить Elasticsearch:

```bash
docker compose stop elasticsearch
```

Создать новое событие индексации:

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

docker compose exec php php artisan messaging:outbox:publish
docker compose logs -f search-index-worker
```

После исчерпания retry вывести dead-letter записи:

```bash
docker compose exec php php artisan search:dead-letter list --index=catalog_products
```

Восстановить Elasticsearch, дождаться закрытия circuit breaker и вернуть запись
с нужным `ID` в очередь:

```bash
docker compose start elasticsearch
sleep 35
docker compose exec php php artisan search:dead-letter requeue --id=<ID>
curl -sS "http://localhost:8080/api/search/products?q=Demo%20Scanner%20Requeued"
```

## Что демонстрирует сценарий

- transactional outbox отделяет запись события от обработки;
- Redis queue отделяет публикацию события от записи в Elasticsearch;
- retry и dead-letter сохраняют документ при сбое;
- ручной requeue возвращает документ в штатную очередь после восстановления.
