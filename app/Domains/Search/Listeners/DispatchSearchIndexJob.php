<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class DispatchSearchIndexJob
{
    public function __construct(
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(SearchIndexRequested $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            IndexSearchDocument::dispatch(
                index: $event->index,
                documentId: $event->documentId,
                document: $event->document,
            );
        });
    }

    private function messageId(SearchIndexRequested $event): string
    {
        return DomainEventContext::eventId($event);
    }
}
