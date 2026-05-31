<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\ProviderOutboxMessage;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class ProviderOutboxPublisher
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    public function publishPending(int $limit = 100): int
    {
        if (! $this->circuitBreaker->allows('rabbitmq')) {
            return 0;
        }

        $published = 0;
        $connection = $this->connections->create();
        $channel = $connection->channel();

        try {
            foreach ($this->pending($limit) as $message) {
                if ($this->publish($channel, $message)) {
                    $published++;
                }
            }

            $this->circuitBreaker->recordSuccess('rabbitmq');
        } catch (Throwable $exception) {
            $this->circuitBreaker->recordFailure('rabbitmq');

            throw $exception;
        } finally {
            $channel->close();
            $connection->close();
        }

        return $published;
    }

    private function publish(mixed $channel, ProviderOutboxMessage $message): bool
    {
        $claimed = DB::transaction(function () use ($message): ?ProviderOutboxMessage {
            /** @var ProviderOutboxMessage|null $locked */
            $locked = ProviderOutboxMessage::query()
                ->whereKey($message->id)
                ->whereIn('status', [ProviderOutboxMessage::STATUS_PENDING, ProviderOutboxMessage::STATUS_FAILED])
                ->lockForUpdate()
                ->first();

            if ($locked === null || ($locked->available_at !== null && $locked->available_at->isFuture())) {
                return null;
            }

            $locked->update([
                'status' => ProviderOutboxMessage::STATUS_PROCESSING,
                'attempts' => $locked->attempts + 1,
            ]);

            return $locked;
        });

        if ($claimed === null) {
            return false;
        }

        try {
            $channel->basic_publish(
                new AMQPMessage(
                    json_encode($claimed->payload, JSON_THROW_ON_ERROR),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'application_headers' => new AMQPTable($claimed->headers),
                    ],
                ),
                $claimed->exchange,
                $claimed->routing_key,
            );

            $claimed->update([
                'status' => ProviderOutboxMessage::STATUS_PUBLISHED,
                'published_at' => now(),
                'last_error' => null,
            ]);

            return true;
        } catch (Throwable $exception) {
            $claimed->update([
                'status' => ProviderOutboxMessage::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
                'available_at' => now()->addSeconds(min(300, max(1, $claimed->attempts))),
            ]);

            throw $exception;
        }
    }

    /**
     * @return Collection<int, ProviderOutboxMessage>
     */
    private function pending(int $limit): Collection
    {
        return ProviderOutboxMessage::query()
            ->whereIn('status', [ProviderOutboxMessage::STATUS_PENDING, ProviderOutboxMessage::STATUS_FAILED])
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
