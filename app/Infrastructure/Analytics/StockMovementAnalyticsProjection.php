<?php

namespace App\Infrastructure\Analytics;

use App\Domains\Inventory\Events\StockChanged;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StockMovementAnalyticsProjection
{
    public function __construct(private readonly ClickHouseClient $clickHouse) {}

    public function enabled(): bool
    {
        return $this->clickHouse->enabled();
    }

    public function initialize(): void
    {
        $this->clickHouse->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS inventory_stock_movements
            (
                movement_id UInt64,
                stock_item_id UInt64,
                warehouse_id UInt64,
                product_id UInt64,
                sku LowCardinality(String),
                reservation_id Nullable(UInt64),
                movement_type LowCardinality(String),
                quantity UInt64,
                reference_type Nullable(String),
                reference_id Nullable(String),
                metadata Nullable(String),
                occurred_at DateTime64(3, 'UTC'),
                ingested_at DateTime64(3, 'UTC') DEFAULT now64(3)
            )
            ENGINE = ReplacingMergeTree(ingested_at)
            PARTITION BY toYYYYMM(occurred_at)
            ORDER BY (stock_item_id, occurred_at, movement_id)
            SQL);
    }

    public function truncate(): void
    {
        $this->clickHouse->query('TRUNCATE TABLE inventory_stock_movements');
    }

    public function storeEvent(StockChanged $event): void
    {
        $this->storeRows([[
            'movement_id' => $event->movement->id,
            'stock_item_id' => $event->stockItem->id,
            'warehouse_id' => $event->stockItem->warehouse_id,
            'product_id' => $event->stockItem->product_id,
            'sku' => $event->stockItem->sku,
            'reservation_id' => $event->movement->reservation_id,
            'movement_type' => $event->movement->type,
            'quantity' => $event->movement->quantity,
            'reference_type' => $event->movement->reference_type,
            'reference_id' => $event->movement->reference_id,
            'metadata' => $event->movement->metadata,
            'occurred_at' => $event->movement->occurred_at?->utc()->format('Y-m-d H:i:s.v'),
        ]]);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function storeRows(iterable $rows): void
    {
        $payload = Collection::make($rows)
            ->map(function (array $row): string {
                if (is_array($row['metadata'])) {
                    $row['metadata'] = json_encode($row['metadata'], JSON_THROW_ON_ERROR);
                }

                return json_encode($row, JSON_THROW_ON_ERROR);
            })
            ->implode("\n");

        if ($payload === '') {
            throw new InvalidArgumentException('ClickHouse stock movement batch must not be empty.');
        }

        $this->clickHouse->query(
            'INSERT INTO inventory_stock_movements FORMAT JSONEachRow',
            $payload."\n",
        );
    }
}
