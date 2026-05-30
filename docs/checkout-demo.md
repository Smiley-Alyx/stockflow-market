# Демонстрация оформления заказа

Этот сценарий показывает законченный бизнес-поток:

```text
корзина → price snapshot → запрос подтверждения → reserve stock → order created
```

После создания draft цена фиксируется в позиции заказа. Последующее изменение прайса не меняет уже созданный заказ. Подтверждение работает асинхронно через transactional outbox: HTTP-запрос переводит заказ в `reservation_pending`, а publisher резервирует остаток и записывает событие `orders.order.created`.

## Подготовка

Запустить базовый локальный стек, установить зависимости и применить миграции:

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

Создать демонстрационные товар, складской остаток и retail-цену:

```bash
docker compose exec php php artisan tinker --execute='
$category = App\Domains\Catalog\Models\Category::query()->firstOrCreate(
    ["slug" => "demo-checkout"],
    ["name" => "Demo checkout", "is_active" => true],
);

$product = App\Domains\Catalog\Models\Product::query()->updateOrCreate(
    ["slug" => "demo-checkout-scanner"],
    [
        "category_id" => $category->id,
        "name" => "Demo Checkout Scanner",
        "sku" => "DEMO-CHECKOUT-001",
        "status" => "published",
        "published_at" => now(),
    ],
);

$warehouse = App\Domains\Inventory\Models\Warehouse::query()->updateOrCreate(
    ["code" => "DEMO-WAW"],
    [
        "name" => "Demo Warsaw Warehouse",
        "city_code" => "waw",
        "city_name" => "Warsaw",
        "latitude" => 52.2296756,
        "longitude" => 21.0122287,
        "is_active" => true,
    ],
);

$stock = App\Domains\Inventory\Models\StockItem::query()->updateOrCreate(
    ["warehouse_id" => $warehouse->id, "product_id" => $product->id],
    [
        "sku" => $product->sku,
        "on_hand_quantity" => 10,
        "reserved_quantity" => 0,
    ],
);

$price = App\Domains\Pricing\Models\ProductPrice::query()->updateOrCreate(
    [
        "product_id" => $product->id,
        "price_type" => "retail",
        "city_code" => null,
        "price_version" => 1,
    ],
    [
        "amount_minor" => 129900,
        "currency" => "USD",
        "is_active" => true,
        "active_from" => now()->subMinute(),
        "active_until" => null,
    ],
);

dump([
    "product_id" => $product->id,
    "stock_item_id" => $stock->id,
    "price_id" => $price->id,
]);
'
```

Запомнить `product_id` из вывода.

## Корзина

Добавить две единицы товара в новую корзину, подставив `product_id`:

```bash
curl -sS -X POST "http://localhost:8080/api/cart/items" \
  -H "Content-Type: application/json" \
  -d '{"product_id": <PRODUCT_ID>, "quantity": 2}'
```

Ответ содержит новую корзину и её `id`. Запомнить `id` как `CART_ID`.

## Снимок цены

Создать draft-заказ:

```bash
curl -sS -X POST "http://localhost:8080/api/orders/draft" \
  -H "Content-Type: application/json" \
  -d '{"cart_id": <CART_ID>}'
```

Ответ должен содержать:

```json
{
  "status": "draft",
  "subtotal_amount_minor": 259800,
  "discount_amount_minor": 0,
  "total_amount_minor": 259800,
  "items": [
    {
      "price_type": "retail",
      "price_version": 1,
      "unit_amount_minor": 129900,
      "line_amount_minor": 259800
    }
  ]
}
```

Запомнить `id` заказа как `ORDER_ID`.

Изменить текущую цену после создания draft:

```bash
docker compose exec php php artisan tinker --execute='
App\Domains\Pricing\Models\ProductPrice::query()
    ->where("product_id", <PRODUCT_ID>)
    ->where("price_type", "retail")
    ->update(["amount_minor" => 9900]);
'
```

Снимок в заказе останется прежним: `unit_amount_minor = 129900`.

## Асинхронное резервирование

Запросить подтверждение заказа:

```bash
curl -sS -X POST "http://localhost:8080/api/orders/<ORDER_ID>/confirm"
```

HTTP-ответ вернёт `status = reservation_pending` и прежний снимок цены. На этом шаге остаток ещё не зарезервирован: запрос только записал `order.confirmation.requested` в outbox.

Проверить состояние до обработки outbox:

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select status from orders_orders where id = <ORDER_ID>;"

docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select sku, on_hand_quantity, reserved_quantity from inventory_stock_items where sku = 'DEMO-CHECKOUT-001';"
```

Ожидаемые значения:

```text
order.status = reservation_pending
stock.on_hand_quantity = 10
stock.reserved_quantity = 0
```

Опубликовать ожидающие outbox-события:

```bash
docker compose exec php php artisan messaging:outbox:publish
```

Обработчик `ReserveInventoryForOrder` создаст резерв, переведёт заказ в `confirmed` и запишет события `order.reservation_succeeded` и `orders.order.created`.

## Проверка результата

Проверить заказ и сохранённый снимок цены:

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select id, status, subtotal_amount_minor, discount_amount_minor, total_amount_minor from orders_orders where id = <ORDER_ID>;"

docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select sku, quantity, price_type, price_version, unit_amount_minor, line_amount_minor from orders_order_items where order_id = <ORDER_ID>;"
```

Проверить резерв:

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select sku, on_hand_quantity, reserved_quantity from inventory_stock_items where sku = 'DEMO-CHECKOUT-001';"

docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select status, quantity, idempotency_key from inventory_reservations order by id desc limit 5;"
```

Ожидаемые значения:

```text
order.status = confirmed
order.total_amount_minor = 259800
order_item.unit_amount_minor = 129900
stock.on_hand_quantity = 10
stock.reserved_quantity = 2
reservation.status = active
reservation.quantity = 2
```

Проверить доменные события заказа:

```bash
docker compose exec postgres psql -U stockflow -d stockflow -c \
  "select event_name, status from messaging_outbox where aggregate_type = 'order' and aggregate_id = '<ORDER_ID>' order by id;"
```

Среди событий должны присутствовать:

```text
order.confirmation.requested
order.reservation_succeeded
orders.order.created
```

## Что демонстрирует сценарий

- корзина отделена от заказа;
- draft-заказ фиксирует retail-цену до подтверждения;
- изменение прайса не меняет снимок существующего заказа;
- запрос подтверждения не резервирует остаток синхронно, а пишет событие в transactional outbox;
- publisher запускает резервирование после HTTP-запроса;
- успешный резерв переводит заказ в `confirmed`;
- `orders.order.created` появляется только после успешного резервирования.
