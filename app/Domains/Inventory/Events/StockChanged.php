<?php

namespace App\Domains\Inventory\Events;

use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockChanged
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'inventory.stock.changed';

    public function __construct(
        public readonly StockItem $stockItem,
        public readonly StockMovement $movement,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => self::NAME,
            'stock' => [
                'warehouse_id' => $this->stockItem->warehouse_id,
                'product_id' => $this->stockItem->product_id,
                'sku' => $this->stockItem->sku,
                'on_hand_quantity' => $this->stockItem->on_hand_quantity,
                'reserved_quantity' => $this->stockItem->reserved_quantity,
                'available_quantity' => $this->stockItem->availableQuantity(),
            ],
            'movement' => [
                'id' => $this->movement->id,
                'type' => $this->movement->type,
                'quantity' => $this->movement->quantity,
                'reference_type' => $this->movement->reference_type,
                'reference_id' => $this->movement->reference_id,
                'occurred_at' => $this->movement->occurred_at?->toJSON(),
            ],
        ];
    }
}
