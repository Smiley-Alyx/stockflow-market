<?php

namespace App\Infrastructure\Messaging;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class InboxConsumer
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $handler
     * @return TReturn|null
     *
     * @throws Throwable
     */
    public function consume(string $messageId, string $consumer, Closure $handler): mixed
    {
        if (! $this->claim($messageId, $consumer)) {
            return null;
        }

        try {
            $result = DB::transaction($handler);

            InboxMessage::query()
                ->where('message_id', $messageId)
                ->where('consumer', $consumer)
                ->update([
                    'status' => InboxMessage::STATUS_PROCESSED,
                    'processed_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return $result;
        } catch (Throwable $exception) {
            InboxMessage::query()
                ->where('message_id', $messageId)
                ->where('consumer', $consumer)
                ->update([
                    'status' => InboxMessage::STATUS_FAILED,
                    'last_error' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    private function claim(string $messageId, string $consumer): bool
    {
        try {
            InboxMessage::query()->create([
                'message_id' => $messageId,
                'consumer' => $consumer,
                'status' => InboxMessage::STATUS_PROCESSING,
            ]);

            return true;
        } catch (QueryException) {
            return DB::transaction(function () use ($messageId, $consumer): bool {
                /** @var InboxMessage|null $existing */
                $existing = InboxMessage::query()
                    ->where('message_id', $messageId)
                    ->where('consumer', $consumer)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null || ! $this->canRetry($existing)) {
                    return false;
                }

                $existing->status = InboxMessage::STATUS_PROCESSING;
                $existing->last_error = null;
                $existing->save();

                return true;
            });
        }
    }

    private function canRetry(InboxMessage $message): bool
    {
        if ($message->status === InboxMessage::STATUS_FAILED) {
            return true;
        }

        return $message->status === InboxMessage::STATUS_PROCESSING
            && $message->updated_at->lte(now()->subSeconds($this->processingTimeoutSeconds()));
    }

    private function processingTimeoutSeconds(): int
    {
        return max(1, (int) config('stockflow.messaging.inbox.processing_timeout_seconds'));
    }
}
