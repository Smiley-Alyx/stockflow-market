<?php

namespace App\Domains\Catalog\Listeners;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Inventory\Events\StockChanged;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class InvalidateCatalogProductsOnStockChanged
{
    public function __construct(
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(StockChanged $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function (): void {
            CatalogCacheKeys::invalidateProducts();
        });
    }

    private function messageId(StockChanged $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
