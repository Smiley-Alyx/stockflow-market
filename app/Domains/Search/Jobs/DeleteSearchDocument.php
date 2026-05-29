<?php

namespace App\Domains\Search\Jobs;

use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Events\SearchIndexCompleted;
use App\Domains\Search\Events\SearchIndexFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Throwable;

class DeleteSearchDocument implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
    ) {
        $this->onQueue(config('stockflow.search.indexing.queue'));

        $this->tries = config('stockflow.messaging.retry.max_attempts');
        $this->timeout = (int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000);
    }

    public function backoff(): int
    {
        return max(0, (int) ceil(config('stockflow.messaging.retry.backoff_ms') / 1000));
    }

    public function handle(SearchIndexer $indexer): void
    {
        $indexer->delete($this->index, $this->documentId);

        SearchIndexCompleted::dispatch($this->index, $this->documentId);
    }

    public function failed(?Throwable $exception): void
    {
        $attempts = max($this->attempts(), (int) config('stockflow.messaging.retry.dead_letter_after_attempts'));
        $failure = $exception?->getMessage() ?? 'Search index deletion failed.';

        App::make(SearchIndexDeadLetterStore::class)->put(
            index: $this->index,
            documentId: $this->documentId,
            document: [],
            attempts: $attempts,
            failure: $failure,
            operation: 'delete',
        );

        SearchIndexFailed::dispatch($this->index, $this->documentId, $attempts, $failure);
    }
}
