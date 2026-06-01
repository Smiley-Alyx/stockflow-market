# Локальный k6 baseline: 2026-06-01

Это baseline локальной разработки, а не оценка production capacity. Прогон
фиксирует точку насыщения Docker Compose стенда и сохраняет нарушенные пороги
как performance evidence.

## Окружение

| Параметр | Значение |
| --- | --- |
| Ревизия | `16bba09` |
| ОС | Ubuntu `22.04.5 LTS`, Linux `6.8.0-111-generic` |
| CPU | AMD Ryzen 5 5500U with Radeon Graphics, `6` ядер / `12` потоков |
| RAM | `15.0 GiB` |
| Docker | `29.5.2` |
| Docker Compose | `5.1.4` |
| k6 | `v2.0.0` из `grafana/k6` |
| Gateway | `http://localhost:8080` |

Стенд уже работал до запуска k6. Прогон использовал явные SKU и product ID,
потому что catalog endpoint возвращал degraded-ответ
`elasticsearch_unavailable`.

## Dataset

| Параметр | Значение |
| --- | ---: |
| Целевые товары | `1` товар: `PERF-SCAN-001`, product `20` |
| Активные цены | `1` retail price |
| Доступный остаток до прогона | `100000` единиц |
| Search queries | `4`: `scanner`, `wireless`, `market`, `sku` |
| Диапазон catalog browse | `8` страниц по `20` товаров |

Это размер явно подготовленного load dataset. Полный снимок количества строк во
всех таблицах БД для исторического прогона не сохранялся. Baseline показывает
поведение смешанного профиля на минимальном dataset, а не масштабирование
большого каталога.

## k6-профиль

Все сценарии запускались одновременно с настройками по умолчанию из
[`../stockflow.js`](../stockflow.js).

| Сценарий | Executor | Расписание | Интенсивность | Лимит VUs |
| --- | --- | --- | --- | ---: |
| `catalog_browse` | `ramping-vus` | `30s` ramp-up, `2m` hold, `20s` ramp-down | до `40` VUs | `40` |
| `sku_reservation_race` | `constant-arrival-rate` | старт через `10s`, длительность `1m` | `25 req/s` | `120` |
| `search_queries` | `ramping-arrival-rate` | `20s` ramp-up, `90s` hold, `20s` ramp-down | до `35 req/s` | `100` |
| `checkout_burst` | `ramping-arrival-rate` | старт через `20s`, `15s` warmup, `30s` burst, `30s` cooldown | `5 req/s` base, до `30 req/s` burst | `150` |

Суммарный настроенный потолок составляет `410` VUs. Catalog browse выполняет
list и detail request, а checkout burst выполняет последовательность cart item,
draft order и confirm, поэтому HTTP RPS не равен сумме arrival rate.

## Команда

```bash
docker run --rm --network host \
  -e BASE_URL=http://localhost:8080 \
  -e RESERVATION_SKU=PERF-SCAN-001 \
  -e CHECKOUT_PRODUCT_ID=20 \
  -e SEARCH_QUERIES=scanner,wireless,market,sku \
  -i grafana/k6 run - < tests/load/k6/stockflow.js
```

## Результаты

Прогон завершился с кодом k6 `99`: нарушены четыре группы порогов.

| Метрика | Результат |
| --- | ---: |
| HTTP requests | `8603` |
| HTTP throughput | `50.52 req/s` |
| Iterations | `8294` |
| Dropped iterations | `1794` |
| Максимальное число активных VUs | `409` |
| HTTP failures | `21.89%` |
| Request duration average | `3.17s` |
| Request duration p90 | `5.48s` |
| Request duration p95 | `8.17s` |
| Request duration p99 | `38.39s` |
| Request duration maximum | `60s` |

| Порог | Результат | Статус |
| --- | ---: | --- |
| `http_req_failed: rate<0.05` | `21.89%` | нарушен |
| `http_req_duration: p(95)<750` | `8.17s` | нарушен |
| `http_req_duration: p(99)<1500` | `38.39s` | нарушен |
| `catalog_errors: count<20` | `2463` | нарушен |
| `checkout_errors: rate<0.15` | `98.22%` | нарушен |
| `reservation_conflicts: rate<0.95` | `0.00%` | соблюдён |

## Интерпретация

Смешанный профиль превышает пропускную способность текущего одиночного
локального gateway runtime. k6 использовал до `409` активных VUs из `410`
настроенных, отбросил `1794` запланированных iterations и сообщил о request
timeouts и connection EOF.

Результат полезен как regression baseline: оптимизации должны снижать latency,
ошибки и dropped iterations при той же команде. Его нельзя использовать для
оценки production capacity. Catalog evidence также включает локальный degraded
Elasticsearch path и application rate limiting.
