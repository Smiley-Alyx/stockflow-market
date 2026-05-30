<?php

namespace App\Console\Commands;

use App\Domains\Inventory\Services\StockMovementRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ArchiveStockMovementsCommand extends Command
{
    protected $signature = 'inventory:stock-movements:archive
        {--days= : Hot-table retention window in days}
        {--batch-size= : Rows moved per transaction}
        {--dry-run : Count matching rows without moving them}';

    protected $description = 'Archive old inventory stock movements out of the hot table.';

    public function handle(StockMovementRetentionService $retention): int
    {
        $days = $this->positiveOption('days', (int) config('stockflow.inventory.stock_movements.retention_days'));
        $batchSize = $this->positiveOption('batch-size', (int) config('stockflow.inventory.stock_movements.archive_batch_size'));

        if ($days === null || $batchSize === null) {
            return self::INVALID;
        }

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = $retention->countArchivable($cutoff);

            $this->info("Dry run: {$count} inventory stock movement(s) older than {$cutoff->toJSON()} can be archived.");

            return self::SUCCESS;
        }

        $archived = $retention->archive($cutoff, $batchSize);

        Log::info('inventory.stock_movements.archived', [
            'archived_count' => $archived,
            'cutoff' => $cutoff->toJSON(),
            'batch_size' => $batchSize,
        ]);

        $this->info("Archived {$archived} inventory stock movement(s).");

        return self::SUCCESS;
    }

    private function positiveOption(string $name, int $default): ?int
    {
        $value = $this->option($name);
        $value = $value === null ? $default : (int) $value;

        if ($value < 1) {
            $this->error("The --{$name} option must be greater than zero.");

            return null;
        }

        return $value;
    }
}
