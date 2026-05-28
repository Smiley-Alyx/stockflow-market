<?php

namespace App\Domains\Search\DeadLetters;

use Illuminate\Support\Collection;

interface SearchIndexDeadLetterStore
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function put(string $index, string $documentId, array $document, int $attempts, string $failure): SearchIndexDeadLetter;

    public function find(int $id): ?SearchIndexDeadLetter;

    /**
     * @return Collection<int, SearchIndexDeadLetter>
     */
    public function list(?string $index = null, ?string $documentId = null, int $limit = 10, int $afterId = 0): Collection;

    public function delete(int $id): void;
}
