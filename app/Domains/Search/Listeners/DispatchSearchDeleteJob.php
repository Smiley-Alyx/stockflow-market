<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Jobs\DeleteSearchDocument;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class DispatchSearchDeleteJob
{
    public function __construct(
        private readonly InboxConsumer $inbox,
    ) {}

    public function handle(SearchIndexDeletionRequested $event): void
    {
        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            DeleteSearchDocument::dispatch(
                index: $event->index,
                documentId: $event->documentId,
            );
        });
    }

    private function messageId(SearchIndexDeletionRequested $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
