<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReservationFailed
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'order.reservation_failed';

    public function __construct(
        public readonly Order $order,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => self::NAME,
            'order_id' => $this->order->id,
            'reason' => $this->reason,
        ];
    }
}
