# Реалистичный k6 baseline: 2026-06-18

Это повтор локального baseline на наполненном каталоге. Цель прогона — получить
измеримое изменение throughput и latency относительно сохраненного baseline
`2026-06-01`, а не оценить production capacity.

## Dataset

| Параметр | Значение |
| --- | ---: |
| Catalog products | `50003` |
| Catalog projections | `50003` |
| Inventory stock items | `350000` |
| Product prices | `189170` |
| Elasticsearch `catalog_products` docs | `50003` |
| Reservation SKU | `AUR-HUB-MINI` |
| Checkout product | `1` |
| Search queries | `AUR,NOR,MEL,VER` |

Данные подготовлены через `php artisan migrate --force`,
`STOCKFLOW_SEED_SEARCH_INDEX=false php artisan db:seed --class=DatabaseSeeder --force`
и `php artisan search:index:rebuild --sync --chunk=500`.

## Изменения перед финальным прогоном

- `perf(search): add projection fallback` — catalog/search больше не возвращают
  пустой degraded-ответ при недоступном Elasticsearch, добавлены индексы для
  projection fallback.
- `perf(runtime): enable php server workers` — локальный gateway запускает
  несколько `php -S` workers через `PHP_CLI_SERVER_WORKERS=8`.
- `perf(load): raise local rate limits` — локальные catalog/search/checkout
  лимиты подняты до `10000`, чтобы k6 baseline не измерял 429 от dev limiter.

## Команда финального прогона

```bash
docker run --rm --network host \
  -e BASE_URL=http://localhost:8080 \
  -e RESERVATION_SKU=AUR-HUB-MINI \
  -e CHECKOUT_PRODUCT_ID=1 \
  -e SEARCH_QUERIES=AUR,NOR,MEL,VER \
  -i grafana/k6 run - < tests/load/k6/stockflow.js
```

Raw output сохранен локально в
`/tmp/stockflow-k6-2026-06-18-workers-rate-limits.txt`.

## Сравнение

| Метрика | 2026-06-01 baseline | 2026-06-18 final |
| --- | ---: | ---: |
| Dataset | `1` целевой товар | `50003` товаров |
| HTTP requests | `8603` | `13350` |
| HTTP throughput | `50.52 req/s` | `78.15 req/s` |
| Iterations | `8294` | `9613` |
| Dropped iterations | `1794` | `195` |
| Максимальное число активных VUs | `409` | `300` |
| HTTP failures | `21.89%` | `17.23%` |
| Request duration average | `3.17s` | `1.07s` |
| Request duration p90 | `5.48s` | `1.56s` |
| Request duration p95 | `8.17s` | `2.49s` |
| Request duration p99 | `38.39s` | `4.82s` |
| Request duration maximum | `60s` | `20.89s` |

## Итог

Latency и throughput улучшились измеримо даже на каталоге на несколько порядков
больше: p95 снизился с `8.17s` до `2.49s`, throughput вырос с `50.52` до
`78.15 req/s`, dropped iterations снизились с `1794` до `195`.

Catalog и search в финальном прогоне проходят без ошибок. Оставшиеся нарушения
порогов сосредоточены в POST-флоу:

| Область | Результат |
| --- | ---: |
| `catalog_errors` | `0` |
| `checkout_errors` | `90.01%` |
| `reservation_conflicts` | `3.91%` |
| `http_req_failed` | `17.23%` |

Следующий этап оптимизации должен разбирать checkout/reservation под k6
отдельно: статусы POST-ответов, CSRF/session поведение VU, lock contention на
одном SKU и корректность ожидаемых статусов сценария.
