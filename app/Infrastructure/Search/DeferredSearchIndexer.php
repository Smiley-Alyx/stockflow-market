<?php

namespace App\Infrastructure\Search;

use App\Domains\Search\Contracts\SearchIndexer;
use Illuminate\Support\Facades\Log;

class DeferredSearchIndexer implements SearchIndexer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function index(string $index, string $documentId, array $document): void
    {
        Log::info('Search document indexing deferred until Elasticsearch adapter is enabled.', [
            'index' => $index,
            'document_id' => $documentId,
            'document' => $document,
        ]);
    }
}
