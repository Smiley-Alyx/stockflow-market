# k6 load scenarios

This folder contains scale-oriented k6 scenarios for the public StockFlow API:

- mass catalog browsing: product list pages plus product detail reads;
- concurrent reservation of one SKU: many requests race on the same stock item;
- search queries: mixed query terms against the search read endpoint;
- checkout burst: cart item creation, draft order creation, and confirmation spikes.

## Run

Start the local stack and prepare data first:

```bash
docker compose up -d --build
docker compose exec php php artisan migrate --force
```

Run all scenarios against the local gateway:

```bash
k6 run tests/load/k6/stockflow.js
```

Or run with Docker when k6 is not installed locally:

```bash
docker run --rm --network host -i grafana/k6 run - < tests/load/k6/stockflow.js
```

Run with explicit data when the first catalog product is not suitable for checkout
or reservation:

```bash
BASE_URL=http://localhost:8080 \
RESERVATION_SKU=SCAN-001 \
CHECKOUT_PRODUCT_ID=1 \
SEARCH_QUERIES=scanner,wireless,barcode \
k6 run tests/load/k6/stockflow.js
```

## Useful knobs

| Variable | Default | Purpose |
| --- | ---: | --- |
| `BASE_URL` | `http://localhost:8080` | API gateway base URL |
| `CATALOG_VUS` | `40` | Peak VUs for catalog browsing |
| `CATALOG_PAGES` | `8` | Product list pages to rotate through |
| `RESERVATION_SKU` | first catalog SKU | SKU used for the reservation race |
| `RESERVATION_RATE` | `25` | Reservation requests per second |
| `RESERVATION_QUANTITY` | `1` | Quantity per reservation request |
| `SEARCH_QUERIES` | `scanner,wireless,market,sku` | Comma-separated search terms |
| `SEARCH_RATE` | `35` | Peak search requests per second |
| `CHECKOUT_PRODUCT_ID` | first catalog product | Product used for checkout burst |
| `CHECKOUT_BURST_RATE` | `30` | Peak checkout starts per second |
| `CHECKOUT_QUANTITY` | `1` | Quantity per cart item |

The script expects an already populated catalog. Checkout also needs an active
price for the selected product, and reservation needs available stock for the
selected SKU. Reservation conflicts are expected under contention and are
tracked as a separate metric.

## Данные о производительности

- [Локальный k6 baseline: 2026-06-01](results/2026-06-01-local-baseline.md):
  профиль, p95, RPS, размер dataset, hardware и ограничения интерпретации.
