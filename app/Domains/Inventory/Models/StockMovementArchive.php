<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'original_id',
    'stock_item_id',
    'reservation_id',
    'type',
    'quantity',
    'reference_type',
    'reference_id',
    'metadata',
    'occurred_at',
    'archived_at',
])]
class StockMovementArchive extends Model
{
    protected $table = 'inventory_stock_movement_archives';

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
