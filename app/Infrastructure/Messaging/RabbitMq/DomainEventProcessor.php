<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\DomainEventContractRegistry;
use App\Infrastructure\Messaging\InboxConsumer;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

class DomainEventProcessor
{
    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly DomainEventContractRegistry $contracts,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $envelope
     */
    public function process(array $headers, array $envelope): void
    {
        $messageId = $this->requiredString($headers, 'message_id');
        $eventName = $this->requiredString($envelope, 'event_name');
        $schemaVersion = $this->requiredInt($envelope, 'schema_version');
        $payload = $this->requiredArray($envelope, 'payload');

        $this->inbox->consume($messageId, self::class, function () use ($eventName, $messageId, $payload, $schemaVersion): void {
            DomainEventContext::withMessageId($messageId, function () use ($eventName, $payload, $schemaVersion): void {
                Event::dispatch($this->contracts->restore($eventName, $schemaVersion, $payload));
            });
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function requiredArray(array $values, string $name): array
    {
        $value = $values[$name] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("Missing domain event field: {$name}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredInt(array $values, string $name): int
    {
        $value = $values[$name] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Missing domain event field: {$name}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredString(array $values, string $name): string
    {
        $value = $values[$name] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing domain event field: {$name}");
        }

        return $value;
    }
}
