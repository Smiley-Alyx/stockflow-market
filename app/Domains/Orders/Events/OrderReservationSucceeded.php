<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReservationSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'order.reservation_succeeded';

    public function __construct(
        public readonly Order $order,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => self::NAME,
            'order_id' => $this->order->id,
            'reserved_at' => $this->order->confirmed_at?->toJSON(),
        ];
    }
}
