<?php

namespace App\Infrastructure\Search\DeadLetters;

use App\Domains\Search\DeadLetters\SearchIndexDeadLetter;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use Illuminate\Support\Collection;

class ArraySearchIndexDeadLetterStore implements SearchIndexDeadLetterStore
{
    /**
     * @var array<int, SearchIndexDeadLetter>
     */
    private static array $records = [];

    private static int $nextId = 1;

    /**
     * @param  array<string, mixed>  $document
     */
    public function put(string $index, string $documentId, array $document, int $attempts, string $failure): SearchIndexDeadLetter
    {
        $record = new SearchIndexDeadLetter(
            id: self::$nextId++,
            index: $index,
            documentId: $documentId,
            document: $document,
            attempts: $attempts,
            failure: $failure,
        );

        self::$records[$record->id] = $record;

        return $record;
    }

    public function find(int $id): ?SearchIndexDeadLetter
    {
        return self::$records[$id] ?? null;
    }

    /**
     * @return Collection<int, SearchIndexDeadLetter>
     */
    public function list(?string $index = null, ?string $documentId = null, int $limit = 10, int $afterId = 0): Collection
    {
        return collect(self::$records)
            ->filter(fn (SearchIndexDeadLetter $record): bool => $record->id > $afterId)
            ->filter(fn (SearchIndexDeadLetter $record): bool => $index === null || $record->index === $index)
            ->filter(fn (SearchIndexDeadLetter $record): bool => $documentId === null || $record->documentId === $documentId)
            ->sortBy('id')
            ->take(max(1, $limit))
            ->values();
    }

    public function delete(int $id): void
    {
        unset(self::$records[$id]);
    }

    public static function reset(): void
    {
        self::$records = [];
        self::$nextId = 1;
    }
}
