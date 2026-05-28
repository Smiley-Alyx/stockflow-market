<?php

namespace App\Domains\Search\Jobs;

use App\Domains\Search\Contracts\SearchIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IndexSearchDocument implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
        public readonly array $document,
    ) {
        $this->onQueue(config('stockflow.search.indexing.queue'));

        $this->tries = config('stockflow.messaging.retry.max_attempts');
        $this->timeout = (int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000);
    }

    public function backoff(): int
    {
        return max(1, (int) ceil(config('stockflow.messaging.retry.backoff_ms') / 1000));
    }

    public function handle(SearchIndexer $indexer): void
    {
        $indexer->index($this->index, $this->documentId, $this->document);
    }

    public function failed(?Throwable $exception): void
    {
        DeadLetterSearchIndexDocument::dispatch(
            index: $this->index,
            documentId: $this->documentId,
            document: $this->document,
            attempts: max($this->attempts(), (int) config('stockflow.messaging.retry.dead_letter_after_attempts')),
            failure: $exception?->getMessage() ?? 'Search indexing failed.',
        );
    }
}
