<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderExpired
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'orders.order.expired';

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
            'status' => $this->order->status,
            'expired_at' => $this->order->expired_at?->toJSON(),
        ];
    }
}
