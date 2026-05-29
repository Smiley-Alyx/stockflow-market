<?php

namespace App\Domains\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cart_id', 'status', 'total_amount_minor', 'currency', 'confirmed_at'])]
class Order extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RESERVATION_PENDING = 'reservation_pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RESERVATION_FAILED = 'reservation_failed';

    protected $table = 'orders_orders';

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'total_amount_minor' => 'integer',
        ];
    }
}
