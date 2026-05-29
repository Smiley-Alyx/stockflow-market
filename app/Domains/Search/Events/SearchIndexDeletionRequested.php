<?php

namespace App\Domains\Search\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SearchIndexDeletionRequested
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'search.index.deletion_requested';

    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
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
        ];
    }
}
