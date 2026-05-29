<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryReserved
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'inventory.reserved';

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
