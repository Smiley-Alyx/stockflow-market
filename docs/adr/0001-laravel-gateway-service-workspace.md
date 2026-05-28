# ADR 0001: Laravel Gateway And Service Workspace

## Status

Accepted

## Context

StockFlow Market is moving toward a marketplace architecture with separate bounded contexts for catalog, inventory, orders, pricing, search, and the public API gateway. The project is still early enough that extracting runtime services would add operational overhead before the domain workflows are stable.

The repository already contains a Laravel application shell and a `services/` workspace with contracts, messaging notes, migrations, source directories, and tests for each planned service.

## Decision

Keep Laravel as the executable gateway and application shell while domain boundaries are designed inside this monorepo. The `services/` workspace is the source of planned service ownership:

- `gateway` owns the external API surface and client-facing orchestration.
- `catalog`, `inventory`, `orders`, `pricing`, and `search` own their domain contracts and future persistence boundaries.
- HTTP contracts live in `contracts/`.
- Domain events and integration messages live in `messaging/`.
- Runtime implementation can start inside Laravel when it helps delivery, but it must follow the ownership boundaries documented under `services/`.

## Consequences

- Local development stays simple: one Laravel runtime can exercise early product scenarios.
- Service boundaries are still visible before physical extraction.
- Contracts and events can evolve before infrastructure is split.
- Cross-domain shortcuts need review because they can make later service extraction harder.
- When a boundary gets enough behavior and tests, it can move from the Laravel shell into its service directory with less ambiguity.

## Next Steps

- Build the first Catalog model inside the Laravel shell while keeping catalog ownership explicit.
- Publish the first catalog domain event contract before wiring async processing.
- Add Search indexing as a consumer of catalog events after product creation is stable.
