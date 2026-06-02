# Минимальные примеры API

Полный контракт gateway находится в [`services/gateway/contracts/openapi.yaml`](../services/gateway/contracts/openapi.yaml). Ниже приведена короткая шпаргалка для локальной проверки основных endpoint.

После запуска базового Docker Compose стенда интерактивная документация Swagger
UI доступна на `http://localhost:8084`.

Подготовить переменные. Значения `PRODUCT_ID`, `CART_ID`, `ORDER_ID` и `SKU` нужно заменить на актуальные данные локальной базы:

```bash
export BASE_URL=http://localhost:8080
export PRODUCT_ID=1
export CART_ID=1
export ORDER_ID=1
export SKU=DEMO-CHECKOUT-001
export EXPIRES_AT="$(docker compose exec -T php php -r 'echo date(DATE_ATOM, time() + 600);')"
```

## Curl

Проверить доступность backend:

```bash
curl -sS "$BASE_URL/health/live"
curl -sS "$BASE_URL/health/ready"
```

Получить блоки главной страницы и каталог:

```bash
curl -sS "$BASE_URL/api/homepage"
curl -sS "$BASE_URL/api/catalog/categories/tree"
curl -sS "$BASE_URL/api/catalog/products?per_page=12"
curl -sS "$BASE_URL/catalog/devices/filter/color-is-black-or-white/price-from-99900-to-129900/apply/"
curl -sS "$BASE_URL/api/catalog/products/demo-checkout-scanner"
```

Получить цену, остаток и результаты поиска:

```bash
curl --globoff -sS "$BASE_URL/api/pricing/prices?product_ids[]=$PRODUCT_ID&city_code=waw"
curl -sS "$BASE_URL/api/inventory/stock?sku=$SKU&city_code=waw"
curl -sS --get --data-urlencode "q=Demo Scanner" "$BASE_URL/api/search/products"
```

Создать прямой резерв остатка:

```bash
curl -sS -X POST "$BASE_URL/api/inventory/reservations" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-reservation-1" \
  -d "{\"product_id\":$PRODUCT_ID,\"quantity\":1,\"reservation_expires_at\":\"$EXPIRES_AT\"}"
```

Создать корзину, draft-заказ и запросить подтверждение:

```bash
curl -sS -X POST "$BASE_URL/api/cart/items" \
  -H "Content-Type: application/json" \
  -d "{\"product_id\":$PRODUCT_ID,\"quantity\":2}"

curl -sS -X POST "$BASE_URL/api/orders/draft" \
  -H "Content-Type: application/json" \
  -d "{\"cart_id\":$CART_ID}"

curl -sS -X POST "$BASE_URL/api/orders/$ORDER_ID/confirm"
```

Обработать outbox-событие подтверждения и зарезервировать остаток:

```bash
docker compose exec php php artisan messaging:outbox:publish
```

После перехода заказа в `confirmed` отметить его оплаченным:

```bash
curl -sS -X POST "$BASE_URL/api/orders/$ORDER_ID/paid"
```

## HTTPie

Те же базовые read-запросы:

```bash
http GET "$BASE_URL/health/live"
http GET "$BASE_URL/api/homepage"
http GET "$BASE_URL/api/catalog/products" per_page==12
http GET "$BASE_URL/catalog/devices/filter/color-is-black-or-white/price-from-99900-to-129900/apply/"
http GET "$BASE_URL/api/pricing/prices" "product_ids[]==$PRODUCT_ID" city_code==waw
http GET "$BASE_URL/api/inventory/stock" sku=="$SKU" city_code==waw
http GET "$BASE_URL/api/search/products" q=="Demo Scanner"
```

Прямой резерв остатка:

```bash
http POST "$BASE_URL/api/inventory/reservations" \
  Idempotency-Key:demo-reservation-2 \
  product_id:="$PRODUCT_ID" \
  quantity:=1 \
  reservation_expires_at="$EXPIRES_AT"
```

Минимальный checkout:

```bash
http POST "$BASE_URL/api/cart/items" product_id:="$PRODUCT_ID" quantity:=2
http POST "$BASE_URL/api/orders/draft" cart_id:="$CART_ID"
http POST "$BASE_URL/api/orders/$ORDER_ID/confirm"
docker compose exec php php artisan messaging:outbox:publish
http POST "$BASE_URL/api/orders/$ORDER_ID/paid"
```

Подробные воспроизводимые сценарии:

- [`docs/catalog-demo.md`](catalog-demo.md) — индексация каталога, поиск, dead-letter и requeue;
- [`docs/checkout-demo.md`](checkout-demo.md) — снимок цены, асинхронное резервирование и `orders.order.created`.
