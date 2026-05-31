<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class RequestProductIndexing
{
    public function __construct(
        private readonly CatalogSearchIndexService $index,
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(ProductCreated|ProductUpdated $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->index->requestProduct($event->product);
        });
    }

    private function messageId(ProductCreated|ProductUpdated $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
