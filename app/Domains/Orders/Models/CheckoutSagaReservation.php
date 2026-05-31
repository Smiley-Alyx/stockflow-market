<?php

namespace App\Domains\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['checkout_saga_id', 'order_item_id', 'reservation_id', 'status'])]
class CheckoutSagaReservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RELEASE_PENDING = 'release_pending';

    public const STATUS_RELEASED = 'released';

    protected $table = 'orders_checkout_saga_reservations';

    /**
     * @return BelongsTo<CheckoutSaga, $this>
     */
    public function saga(): BelongsTo
    {
        return $this->belongsTo(CheckoutSaga::class, 'checkout_saga_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
