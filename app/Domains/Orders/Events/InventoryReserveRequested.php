<?php

namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryReserveRequested
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'inventory.reserve.requested';

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
            'items' => $this->order->items
                ->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                ])
                ->values()
                ->all(),
        ];
    }
}
