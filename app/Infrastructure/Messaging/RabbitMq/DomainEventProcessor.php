<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

class DomainEventProcessor
{
    public function __construct(private readonly InboxConsumer $inbox) {}

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $envelope
     */
    public function process(array $headers, array $envelope): void
    {
        $messageId = $this->requiredString($headers, 'message_id');
        $serializedEvent = $this->requiredString($envelope, 'serialized_event');

        $this->inbox->consume($messageId, self::class, function () use ($messageId, $serializedEvent): void {
            DomainEventContext::withMessageId($messageId, function () use ($serializedEvent): void {
                Event::dispatch(unserialize(base64_decode($serializedEvent), ['allowed_classes' => true]));
            });
        });
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
