<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Jobs\DeleteSearchDocument;

class DispatchSearchDeleteJob
{
    public function handle(SearchIndexDeletionRequested $event): void
    {
        DeleteSearchDocument::dispatch(
            index: $event->index,
            documentId: $event->documentId,
        );
    }
}
