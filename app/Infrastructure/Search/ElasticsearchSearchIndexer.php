<?php

namespace App\Infrastructure\Search;

use App\Domains\Search\Contracts\BulkSearchIndexer;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ElasticsearchSearchIndexer implements BulkSearchIndexer, SearchIndexer
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

    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function indexMany(string $index, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        if (! $this->circuitBreaker->allows('elasticsearch')) {
            throw new RuntimeException('Elasticsearch circuit breaker is open.');
        }

        $payload = '';

        foreach ($documents as $documentId => $document) {
            $payload .= json_encode([
                'index' => [
                    '_index' => $index,
                    '_id' => $documentId,
                ],
            ], JSON_THROW_ON_ERROR)."\n";
            $payload .= json_encode($document, JSON_THROW_ON_ERROR)."\n";
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.bulk_timeout_ms') / 1000))
                ->withBody($payload, 'application/x-ndjson')
                ->post('/_bulk')
                ->throw()
                ->json();

            if (($response['errors'] ?? false) === true) {
                throw new RuntimeException('Elasticsearch bulk indexing returned document errors.');
            }

            $this->circuitBreaker->recordSuccess('elasticsearch');
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            throw $exception;
        }
    }
}
