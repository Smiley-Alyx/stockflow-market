<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\DomainEventRecorder;
use App\Infrastructure\Messaging\InboxConsumer;

class RequestProductIndexing
{
    public function __construct(
        private readonly DomainEventRecorder $events,
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(ProductCreated|ProductUpdated $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $product = $event->product;

            $this->events->record(new SearchIndexRequested(
                index: 'catalog_products',
                documentId: (string) $product->id,
                document: [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'sku' => $product->sku,
                    'status' => $product->status,
                    'published_at' => $product->published_at?->toJSON(),
                ],
            ), 'search_document', 'catalog_products:'.$product->id);
        });
    }

    private function messageId(ProductCreated|ProductUpdated $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
