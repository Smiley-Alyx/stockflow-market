<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\IndexSearchDocument;

class DispatchSearchIndexJob
{
    public function handle(SearchIndexRequested $event): void
    {
        IndexSearchDocument::dispatch(
            index: $event->index,
            documentId: $event->documentId,
            document: $event->document,
        );
    }
}
