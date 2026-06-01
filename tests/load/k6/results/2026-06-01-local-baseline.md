# Local k6 baseline: 2026-06-01

This is a local development baseline, not a production capacity claim. The run
captures the current saturation point of the Docker Compose stack and keeps the
failed thresholds visible as performance evidence.

## Environment

| Item | Value |
| --- | --- |
| Revision | `16bba09` |
| Host | Linux `6.8.0-111-generic`, 12 CPU, 15.0 GiB RAM |
| Docker | `29.5.2` |
| Docker Compose | `5.1.4` |
| k6 | `v2.0.0` from `grafana/k6` |
| Gateway | `http://localhost:8080` |
| Test data | `PERF-SCAN-001`, product `20`, active retail price, `100000` available units before the run |

The local stack was already running. The test used explicit product and SKU
values because the catalog endpoint was serving its degraded
`elasticsearch_unavailable` response during the run.

## Command

```bash
docker run --rm --network host \
  -e BASE_URL=http://localhost:8080 \
  -e RESERVATION_SKU=PERF-SCAN-001 \
  -e CHECKOUT_PRODUCT_ID=20 \
  -e SEARCH_QUERIES=scanner,wireless,market,sku \
  -i grafana/k6 run - < tests/load/k6/stockflow.js
```

## Results

The run finished with k6 exit code `99` because four thresholds were crossed.

| Metric | Result |
| --- | ---: |
| HTTP requests | `8603` |
| HTTP throughput | `50.52 req/s` |
| Iterations | `8294` |
| Dropped iterations | `1794` |
| Maximum active VUs | `409` |
| HTTP failures | `21.89%` |
| Request duration average | `3.17s` |
| Request duration p90 | `5.48s` |
| Request duration p95 | `8.17s` |
| Request duration p99 | `38.39s` |
| Request duration maximum | `60s` |

| Threshold | Result | Status |
| --- | ---: | --- |
| `http_req_failed: rate<0.05` | `21.89%` | failed |
| `http_req_duration: p(95)<750` | `8.17s` | failed |
| `http_req_duration: p(99)<1500` | `38.39s` | failed |
| `catalog_errors: count<20` | `2463` | failed |
| `checkout_errors: rate<0.15` | `98.22%` | failed |
| `reservation_conflicts: rate<0.95` | `0.00%` | passed |

## Interpretation

The mixed profile exceeds the capacity of the current single local gateway
runtime. k6 reached `410` configured VUs, dropped `1794` scheduled iterations,
and reported request timeouts and connection EOF errors.

The result is useful as a regression baseline: optimization work should reduce
latency, failures, and dropped iterations under the same command. It should not
be used to estimate production capacity. Catalog evidence also includes the
local degraded Elasticsearch path and application rate limiting.
