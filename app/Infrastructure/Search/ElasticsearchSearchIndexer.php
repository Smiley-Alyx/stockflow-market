<?php

namespace App\Infrastructure\Search;

use App\Domains\Search\Contracts\SearchIndexer;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ElasticsearchSearchIndexer implements SearchIndexer
{
    public function __construct(
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker ??= app(CircuitBreaker::class);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function index(string $index, string $documentId, array $document): void
    {
        if (! $this->circuitBreaker->allows('elasticsearch')) {
            throw new RuntimeException('Elasticsearch circuit breaker is open.');
        }

        try {
            Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->asJson()
                ->put('/'.rawurlencode($index).'/_doc/'.rawurlencode($documentId), $document)
                ->throw();

            $this->circuitBreaker->recordSuccess('elasticsearch');
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            throw $exception;
        }
    }

    public function delete(string $index, string $documentId): void
    {
        if (! $this->circuitBreaker->allows('elasticsearch')) {
            throw new RuntimeException('Elasticsearch circuit breaker is open.');
        }

        try {
            Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->delete('/'.rawurlencode($index).'/_doc/'.rawurlencode($documentId))
                ->throw();

            $this->circuitBreaker->recordSuccess('elasticsearch');
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            throw $exception;
        }
    }
}
