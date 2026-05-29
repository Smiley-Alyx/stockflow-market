<?php

namespace App\Infrastructure\Messaging;

use InvalidArgumentException;

class DomainEventRecorder
{
    public function record(object $event, ?string $aggregateType = null, ?string $aggregateId = null): OutboxMessage
    {
        if (! method_exists($event, 'payload')) {
            throw new InvalidArgumentException('Domain event must expose a payload method.');
        }

        $eventName = defined($event::class.'::NAME') ? constant($event::class.'::NAME') : $event::class;

        return OutboxMessage::query()->create([
            'event_name' => $eventName,
            'event_class' => $event::class,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $event->payload(),
            'serialized_event' => base64_encode(serialize($event)),
            'status' => OutboxMessage::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }
}
