<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function receive(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RECEIVED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function reserve(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RESERVED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function release(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RELEASED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function deduct(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_DEDUCTED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function returnStock(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RETURNED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function change(StockItem $stockItem, string $type, int $quantity, ?string $referenceType, ?string $referenceId, ?array $metadata): StockMovement
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Stock movement quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($stockItem, $type, $quantity, $referenceType, $referenceId, $metadata): StockMovement {
            /** @var StockItem $locked */
            $locked = StockItem::query()
                ->whereKey($stockItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->applyQuantityChange($locked, $type, $quantity);
            $locked->save();

            $movement = $locked->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);

            /** @var StockItem $changed */
            $changed = $locked->fresh();

            StockChanged::dispatch($changed, $movement);

            return $movement;
        });
    }

    private function applyQuantityChange(StockItem $stockItem, string $type, int $quantity): void
    {
        match ($type) {
            StockMovement::TYPE_RECEIVED => $stockItem->on_hand_quantity += $quantity,
            StockMovement::TYPE_RETURNED => $stockItem->on_hand_quantity += $quantity,
            StockMovement::TYPE_RESERVED => $this->reserveQuantity($stockItem, $quantity),
            StockMovement::TYPE_RELEASED => $this->releaseQuantity($stockItem, $quantity),
            StockMovement::TYPE_DEDUCTED => $this->deductQuantity($stockItem, $quantity),
            default => throw new InvalidArgumentException("Unsupported stock movement type {$type}."),
        };
    }

    private function reserveQuantity(StockItem $stockItem, int $quantity): void
    {
        $available = $stockItem->availableQuantity();

        if ($available < $quantity) {
            throw InsufficientStock::available($stockItem->sku, $quantity, $available);
        }

        $stockItem->reserved_quantity += $quantity;
    }

    private function releaseQuantity(StockItem $stockItem, int $quantity): void
    {
        if ($stockItem->reserved_quantity < $quantity) {
            throw InsufficientStock::reserved($stockItem->sku, $quantity, $stockItem->reserved_quantity);
        }

        $stockItem->reserved_quantity -= $quantity;
    }

    private function deductQuantity(StockItem $stockItem, int $quantity): void
    {
        if ($stockItem->reserved_quantity < $quantity) {
            throw InsufficientStock::reserved($stockItem->sku, $quantity, $stockItem->reserved_quantity);
        }

        $stockItem->reserved_quantity -= $quantity;
        $stockItem->on_hand_quantity -= $quantity;
    }
}
