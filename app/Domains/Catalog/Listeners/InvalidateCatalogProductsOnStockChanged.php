<?php

namespace App\Domains\Catalog\Listeners;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Inventory\Events\StockChanged;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class InvalidateCatalogProductsOnStockChanged
{
    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly CatalogProjectionService $projections,
    ) {}

    public function handle(StockChanged $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->projections->syncAvailability($event->stockItem->product_id);
            CatalogCacheKeys::invalidateProducts();
        });
    }

    private function messageId(StockChanged $event): string
    {
        return DomainEventContext::eventId($event);
    }
}
