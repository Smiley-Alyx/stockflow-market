<?php

namespace App\Infrastructure\Search\DeadLetters;

use App\Domains\Search\DeadLetters\SearchIndexDeadLetter;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class RedisSearchIndexDeadLetterStore implements SearchIndexDeadLetterStore
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function put(string $index, string $documentId, array $document, int $attempts, string $failure): SearchIndexDeadLetter
    {
        $id = (int) $this->redis()->incr($this->sequenceKey());
        $record = new SearchIndexDeadLetter($id, $index, $documentId, $document, $attempts, $failure);

        $this->redis()->set($this->recordKey($id), json_encode($this->payloadFor($record), JSON_THROW_ON_ERROR));
        $this->redis()->zadd($this->indexKey(), $id, (string) $id);

        return $record;
    }

    public function find(int $id): ?SearchIndexDeadLetter
    {
        $payload = $this->redis()->get($this->recordKey($id));

        return is_string($payload) ? $this->recordFrom($payload) : null;
    }

    /**
     * @return Collection<int, SearchIndexDeadLetter>
     */
    public function list(?string $index = null, ?string $documentId = null, int $limit = 10, int $afterId = 0): Collection
    {
        $ids = $this->redis()->zrangebyscore($this->indexKey(), '('.$afterId, '+inf', [
            'limit' => [0, max(1, $limit * 4)],
        ]);

        return collect($ids)
            ->map(fn (string $id): ?SearchIndexDeadLetter => $this->find((int) $id))
            ->filter()
            ->filter(fn (SearchIndexDeadLetter $record): bool => $index === null || $record->index === $index)
            ->filter(fn (SearchIndexDeadLetter $record): bool => $documentId === null || $record->documentId === $documentId)
            ->take(max(1, $limit))
            ->values();
    }

    public function delete(int $id): void
    {
        $this->redis()->del($this->recordKey($id));
        $this->redis()->zrem($this->indexKey(), (string) $id);
    }

    private function redis(): mixed
    {
        return Redis::connection(config('stockflow.search.indexing.dead_letter_redis_connection'));
    }

    private function sequenceKey(): string
    {
        return $this->prefix().':seq';
    }

    private function indexKey(): string
    {
        return $this->prefix().':ids';
    }

    private function recordKey(int $id): string
    {
        return $this->prefix().':'.$id;
    }

    private function prefix(): string
    {
        return config('stockflow.search.indexing.dead_letter_redis_key');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(SearchIndexDeadLetter $record): array
    {
        return [
            'id' => $record->id,
            'index' => $record->index,
            'document_id' => $record->documentId,
            'document' => $record->document,
            'attempts' => $record->attempts,
            'failure' => $record->failure,
        ];
    }

    private function recordFrom(string $payload): ?SearchIndexDeadLetter
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return null;
        }

        return new SearchIndexDeadLetter(
            id: (int) $data['id'],
            index: (string) $data['index'],
            documentId: (string) $data['document_id'],
            document: is_array($data['document']) ? $data['document'] : [],
            attempts: (int) $data['attempts'],
            failure: (string) $data['failure'],
        );
    }
}
