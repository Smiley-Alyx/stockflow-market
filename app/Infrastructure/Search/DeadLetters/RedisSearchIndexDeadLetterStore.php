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
    public function put(string $index, string $documentId, array $document, int $attempts, string $failure, string $operation = 'index'): SearchIndexDeadLetter
    {
        $id = (int) $this->redis()->incr($this->sequenceKey());
        $record = new SearchIndexDeadLetter($id, $index, $documentId, $document, $attempts, $failure, $operation);

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
        $records = collect();
        $cursor = $afterId;
        $pageSize = max(1, $limit * 4);

        do {
            $ids = $this->redis()->zrangebyscore($this->indexKey(), '('.$cursor, '+inf', [
                'limit' => [0, $pageSize],
            ]);

            foreach ($ids as $id) {
                $cursor = (int) $id;
                $record = $this->find($cursor);

                if (! $record instanceof SearchIndexDeadLetter) {
                    continue;
                }

                if ($index !== null && $record->index !== $index) {
                    continue;
                }

                if ($documentId !== null && $record->documentId !== $documentId) {
                    continue;
                }

                $records->push($record);

                if ($records->count() >= max(1, $limit)) {
                    return $records->values();
                }
            }
        } while (count($ids) === $pageSize);

        return $records->values();
    }

    public function delete(int $id): void
    {
        $this->redis()->del($this->recordKey($id));
        $this->redis()->zrem($this->indexKey(), (string) $id);
    }

    public function count(): int
    {
        return (int) $this->redis()->zcard($this->indexKey());
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
            'operation' => $record->operation,
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
            operation: (string) ($data['operation'] ?? 'index'),
        );
    }
}
