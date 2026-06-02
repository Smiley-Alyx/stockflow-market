<?php

namespace App\Console\Commands;

use App\Infrastructure\Analytics\StockMovementAnalyticsProjection;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RebuildStockMovementAnalyticsCommand extends Command
{
    protected $signature = 'analytics:stock-movements:rebuild
        {--batch-size= : Rows inserted into ClickHouse per request}';

    protected $description = 'Rebuild the ClickHouse inventory stock movement analytics projection.';

    public function handle(StockMovementAnalyticsProjection $projection): int
    {
        if (! $projection->enabled()) {
            $this->error('ClickHouse analytics is disabled. Set CLICKHOUSE_ENABLED=true.');

            return self::FAILURE;
        }

        $batchSize = $this->batchSize();

        if ($batchSize === null) {
            return self::INVALID;
        }

        $projection->initialize();
        $projection->truncate();

        $count = $this->project($this->hotMovements(), 'inventory_stock_movements.id', 'movement_id', $batchSize, $projection);
        $count += $this->project($this->archivedMovements(), 'inventory_stock_movement_archives.original_id', 'movement_id', $batchSize, $projection);

        $this->info("Projected {$count} inventory stock movement(s) into ClickHouse.");

        return self::SUCCESS;
    }

    private function batchSize(): ?int
    {
        $value = $this->option('batch-size');
        $value = $value === null
            ? (int) config('stockflow.analytics.stock_movements.rebuild_batch_size')
            : (int) $value;

        if ($value < 1) {
            $this->error('The --batch-size option must be greater than zero.');

            return null;
        }

        return min($value, 5000);
    }

    private function hotMovements(): Builder
    {
        return DB::table('inventory_stock_movements')
            ->join('inventory_stock_items', 'inventory_stock_items.id', '=', 'inventory_stock_movements.stock_item_id')
            ->select([
                'inventory_stock_movements.id as movement_id',
                'inventory_stock_movements.stock_item_id',
                'inventory_stock_items.warehouse_id',
                'inventory_stock_items.product_id',
                'inventory_stock_items.sku',
                'inventory_stock_movements.reservation_id',
                'inventory_stock_movements.type as movement_type',
                'inventory_stock_movements.quantity',
                'inventory_stock_movements.reference_type',
                'inventory_stock_movements.reference_id',
                'inventory_stock_movements.metadata',
                'inventory_stock_movements.occurred_at',
            ]);
    }

    private function archivedMovements(): Builder
    {
        return DB::table('inventory_stock_movement_archives')
            ->join('inventory_stock_items', 'inventory_stock_items.id', '=', 'inventory_stock_movement_archives.stock_item_id')
            ->select([
                'inventory_stock_movement_archives.original_id as movement_id',
                'inventory_stock_movement_archives.stock_item_id',
                'inventory_stock_items.warehouse_id',
                'inventory_stock_items.product_id',
                'inventory_stock_items.sku',
                'inventory_stock_movement_archives.reservation_id',
                'inventory_stock_movement_archives.type as movement_type',
                'inventory_stock_movement_archives.quantity',
                'inventory_stock_movement_archives.reference_type',
                'inventory_stock_movement_archives.reference_id',
                'inventory_stock_movement_archives.metadata',
                'inventory_stock_movement_archives.occurred_at',
            ]);
    }

    private function project(
        Builder $query,
        string $column,
        string $alias,
        int $batchSize,
        StockMovementAnalyticsProjection $projection,
    ): int {
        $count = 0;

        $query->chunkById($batchSize, function ($rows) use (&$count, $projection): void {
            $projection->storeRows($rows
                ->map(fn (object $row): array => (array) $row)
                ->all());

            $count += $rows->count();
        }, $column, $alias);

        return $count;
    }
}
