<?php

namespace App\Domains\Search\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SearchIndexRequested
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'search.index.requested';

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public readonly string $index,
        public readonly string $documentId,
        public readonly array $document,
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
            'document' => $this->document,
        ];
    }
}
