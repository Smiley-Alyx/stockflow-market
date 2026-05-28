# ADR 0002: Runtime Configuration Boundaries

## Status

Accepted

## Context

The project is moving toward queue-driven catalog indexing, cache-backed reads, worker pools, retries, and backpressure controls. These concerns need explicit operational knobs before the code starts depending on hardcoded timeouts, queue names, or retry budgets.

Laravel already owns framework configuration, but StockFlow needs a project-level configuration namespace for cross-cutting highload policies that will later be shared by extracted services.

## Decision

Introduce `config/stockflow.php` as the application-level runtime contract for StockFlow-specific settings:

- `runtime` keeps service identity, request timeout, and shutdown budget.
- `catalog.cache` keeps cache TTLs for catalog read models.
- `search.indexing` keeps queue, batch, concurrency, and downstream timeout limits.
- `messaging` keeps event bus selection and retry/dead-letter policy.

The values are environment-overridable through `STOCKFLOW_*` variables and documented in `.env.example`.

## Consequences

- Future queue workers and adapters can consume named config values instead of local constants.
- Load tests can tune batch size, concurrency, and retry budgets without code changes.
- Extracted services can copy the same config contract while replacing Laravel-specific wiring.
- The config file is intentionally conservative; unused settings should only become behavior when the corresponding worker, cache, or adapter is implemented.

## Next Steps

- Wire search indexing jobs to `stockflow.search.indexing`.
- Use `stockflow.messaging.retry` when the first asynchronous consumer is introduced.
- Add health/readiness checks that expose dependency and shutdown-budget assumptions.
