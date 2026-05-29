<?php

namespace App\Domains\Search\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SearchIndexFailed
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'search.index.failed';

    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
        public readonly int $attempts,
        public readonly string $failure,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => self::NAME,
            'index' => $this->index,
            'document_id' => $this->documentId,
            'attempts' => $this->attempts,
            'failure' => $this->failure,
        ];
    }
}
