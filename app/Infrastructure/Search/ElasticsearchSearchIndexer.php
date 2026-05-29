<?php

namespace App\Infrastructure\Search;

use App\Domains\Search\Contracts\SearchIndexer;
use Illuminate\Support\Facades\Http;

class ElasticsearchSearchIndexer implements SearchIndexer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function index(string $index, string $documentId, array $document): void
    {
        Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
            ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
            ->asJson()
            ->put('/'.rawurlencode($index).'/_doc/'.rawurlencode($documentId), $document)
            ->throw();
    }

    public function delete(string $index, string $documentId): void
    {
        Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
            ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
            ->delete('/'.rawurlencode($index).'/_doc/'.rawurlencode($documentId))
            ->throw();
    }
}
