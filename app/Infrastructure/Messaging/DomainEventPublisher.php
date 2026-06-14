<?php

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\DomainEventRabbitMqPublisher;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

class DomainEventPublisher
{
    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
        private readonly DomainEventRabbitMqPublisher $rabbitMq,
        private readonly DomainEventContractRegistry $contracts,
    ) {}

    public function publishPending(int $limit = 100): int
    {
        $published = 0;

        $this->pending($limit)->each(function (OutboxMessage $message) use (&$published): void {
            if ($this->publish($message)) {
                $published++;
            }
        });

        return $published;
    }

    public function publish(OutboxMessage $message): bool
    {
        if ($this->usesRabbitMq() && ! $this->circuitBreaker->allows('rabbitmq')) {
            return false;
        }

        $claimed = DB::transaction(function () use ($message): ?OutboxMessage {
            /** @var OutboxMessage|null $locked */
            $locked = OutboxMessage::query()
                ->whereKey($message->id)
                ->whereIn('status', [OutboxMessage::STATUS_PENDING, OutboxMessage::STATUS_FAILED])
                ->lockForUpdate()
                ->first();

            if ($locked === null || ($locked->available_at !== null && $locked->available_at->isFuture())) {
                return null;
            }

            $locked->status = OutboxMessage::STATUS_PROCESSING;
            $locked->attempts++;
            $locked->save();

            return $locked;
        });

        if ($claimed === null) {
            return false;
        }

        try {
            if ($this->usesRabbitMq()) {
                $this->rabbitMq->publish($claimed);
            } else {
                DomainEventContext::withMessageId((string) $claimed->id, function () use ($claimed): void {
                    Event::dispatch($this->contracts->restore(
                        $claimed->event_name,
                        $claimed->schema_version,
                        $claimed->payload,
                    ));
                });
            }

            $claimed->status = OutboxMessage::STATUS_PUBLISHED;
            $claimed->published_at = now();
            $claimed->last_error = null;
            $claimed->save();

            if ($this->usesRabbitMq()) {
                $this->circuitBreaker->recordSuccess('rabbitmq');
            }

            return true;
        } catch (Throwable $exception) {
            if ($this->usesRabbitMq()) {
                $this->circuitBreaker->recordFailure('rabbitmq');
            }

            $claimed->status = OutboxMessage::STATUS_FAILED;
            $claimed->last_error = $exception->getMessage();
            $claimed->available_at = now()->addSeconds($this->backoffSeconds($claimed->attempts));
            $claimed->save();

            throw $exception;
        }
    }

    /**
     * @return Collection<int, OutboxMessage>
     */
    private function pending(int $limit): Collection
    {
        return OutboxMessage::query()
            ->whereIn('status', [OutboxMessage::STATUS_PENDING, OutboxMessage::STATUS_FAILED])
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = max(1, (int) ceil(config('stockflow.messaging.retry.backoff_ms') / 1000));

        return min(300, $base * max(1, $attempts));
    }

    private function usesRabbitMq(): bool
    {
        return config('stockflow.messaging.event_bus') === 'rabbitmq';
    }
}
