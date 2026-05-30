<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class StockMovementRetentionService
{
    public function archive(CarbonInterface $cutoff, int $batchSize): int
    {
        $batchSize = max(1, min($batchSize, 5000));
        $archived = 0;

        do {
            $archivedInBatch = $this->archiveBatch($cutoff, $batchSize);
            $archived += $archivedInBatch;
        } while ($archivedInBatch === $batchSize);

        return $archived;
    }

    public function countArchivable(CarbonInterface $cutoff): int
    {
        return StockMovement::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();
    }

    private function archiveBatch(CarbonInterface $cutoff, int $batchSize): int
    {
        return DB::transaction(function () use ($cutoff, $batchSize): int {
            $movements = StockMovement::query()
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($movements->isEmpty()) {
                return 0;
            }

            $archivedAt = now();

            DB::table('inventory_stock_movement_archives')->insert($movements
                ->map(fn (StockMovement $movement): array => [
                    'original_id' => $movement->id,
                    'stock_item_id' => $movement->stock_item_id,
                    'reservation_id' => $movement->reservation_id,
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'metadata' => $movement->metadata === null ? null : json_encode($movement->metadata, JSON_THROW_ON_ERROR),
                    'occurred_at' => $movement->occurred_at,
                    'created_at' => $movement->created_at,
                    'updated_at' => $movement->updated_at,
                    'archived_at' => $archivedAt,
                ])
                ->all());

            StockMovement::query()
                ->whereKey($movements->modelKeys())
                ->delete();

            return $movements->count();
        });
    }
}
