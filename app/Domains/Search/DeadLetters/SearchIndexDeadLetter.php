<?php

namespace App\Domains\Search\DeadLetters;

class SearchIndexDeadLetter
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public readonly int $id,
        public readonly string $index,
        public readonly string $documentId,
        public readonly array $document,
        public readonly int $attempts,
        public readonly string $failure,
        public readonly string $operation = 'index',
    ) {
        //
    }
}
