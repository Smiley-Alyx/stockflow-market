<?php

namespace App\Domains\Search\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeadLetterSearchIndexDocument implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
        public readonly array $document,
        public readonly int $attempts,
        public readonly string $failure,
    ) {
        $this->onQueue(config('stockflow.search.indexing.dead_letter_queue'));
    }

    public function handle(): void
    {
        Log::warning('Search indexing document moved to dead letter queue.', [
            'index' => $this->index,
            'document_id' => $this->documentId,
            'attempts' => $this->attempts,
            'failure' => $this->failure,
        ]);
    }
}
