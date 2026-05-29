<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_item_id', 'type', 'quantity', 'reference_type', 'reference_id', 'metadata', 'occurred_at'])]
class StockMovement extends Model
{
    public const TYPE_RECEIVED = 'received';

    public const TYPE_RESERVED = 'reserved';

    public const TYPE_RELEASED = 'released';

    public const TYPE_DEDUCTED = 'deducted';

    public const TYPE_RETURNED = 'returned';

    protected $table = 'inventory_stock_movements';

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }
}
