<?php

namespace App\Domains\Orders\Listeners;

use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Services\OrderService;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class ReserveInventoryForOrder
{
    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly OrderService $orders,
    ) {}

    public function handle(OrderConfirmationRequested $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->orders->reserveInventory($event->order->id);
        });
    }

    private function messageId(OrderConfirmationRequested $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
