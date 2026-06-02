<?php

namespace App\Domains\Inventory\Listeners;

use App\Domains\Inventory\Events\StockChanged;
use App\Infrastructure\Analytics\StockMovementAnalyticsProjection;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class ProjectStockMovementAnalytics
{
    public function __construct(
        private readonly StockMovementAnalyticsProjection $projection,
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(StockChanged $event): void
    {
        if (! $this->projection->enabled()) {
            return;
        }

        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->projection->storeEvent($event);
        });
    }

    private function messageId(StockChanged $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
