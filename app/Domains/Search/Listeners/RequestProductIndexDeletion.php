<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\DomainEventRecorder;
use App\Infrastructure\Messaging\InboxConsumer;

class RequestProductIndexDeletion
{
    public function __construct(
        private readonly DomainEventRecorder $events,
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(ProductArchived $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->events->record(new SearchIndexDeletionRequested(
                index: 'catalog_products',
                documentId: (string) $event->product->id,
            ), 'search_document', 'catalog_products:'.$event->product->id);
        });
    }

    private function messageId(ProductArchived $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
