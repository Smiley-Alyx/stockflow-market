<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_item_id', 'idempotency_key', 'quantity', 'status', 'reservation_expires_at', 'canceled_at', 'metadata'])]
class Reservation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'inventory_reservations';

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'canceled_at' => 'datetime',
            'metadata' => 'array',
            'quantity' => 'integer',
            'reservation_expires_at' => 'datetime',
        ];
    }
}
