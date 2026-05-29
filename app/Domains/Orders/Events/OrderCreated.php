<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'orders.order.created';

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
            'total_amount_minor' => $this->order->total_amount_minor,
            'currency' => $this->order->currency,
            'created_at' => $this->order->confirmed_at?->toJSON(),
        ];
    }
}
