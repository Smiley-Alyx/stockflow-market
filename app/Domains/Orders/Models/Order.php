<?php

namespace App\Domains\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['cart_id', 'status', 'city_code', 'promo_code', 'subtotal_amount_minor', 'discount_amount_minor', 'total_amount_minor', 'currency', 'payment_method', 'recipient_name', 'recipient_phone', 'delivery_country_code', 'delivery_city', 'delivery_postal_code', 'delivery_address_line_1', 'delivery_address_line_2', 'checkout_at', 'confirmed_at', 'paid_at', 'cancelled_at', 'expired_at'])]
class Order extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RESERVATION_PENDING = 'reservation_pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RESERVATION_FAILED = 'reservation_failed';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'orders_orders';

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return HasOne<CheckoutSaga, $this>
     */
    public function checkoutSaga(): HasOne
    {
        return $this->hasOne(CheckoutSaga::class);
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
            'checkout_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'subtotal_amount_minor' => 'integer',
            'discount_amount_minor' => 'integer',
            'total_amount_minor' => 'integer',
        ];
    }
}
