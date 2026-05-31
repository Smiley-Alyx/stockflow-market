<?php

namespace App\Domains\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'correlation_id', 'payment_id', 'authorization_id', 'capture_id', 'status', 'failure_reason'])]
class CheckoutSaga extends Model
{
    public const STATUS_RESERVING_STOCK = 'reserving_stock';

    public const STATUS_AUTHORIZING_PAYMENT = 'authorizing_payment';

    public const STATUS_CAPTURING_PAYMENT = 'capturing_payment';

    public const STATUS_CREATING_SHIPMENTS = 'creating_shipments';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'orders_checkout_sagas';

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<CheckoutSagaReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(CheckoutSagaReservation::class);
    }
}
