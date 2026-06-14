<?php

namespace App\Infrastructure\Messaging;

class DomainEventRecorder
{
    public function __construct(private readonly DomainEventContractRegistry $contracts) {}

    public function record(object $event, ?string $aggregateType = null, ?string $aggregateId = null): OutboxMessage
    {
        $contract = $this->contracts->contract($event);

        return OutboxMessage::query()->create([
            'event_name' => $contract['event_name'],
            'schema_version' => $contract['schema_version'],
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $contract['payload'],
            'status' => OutboxMessage::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }
}
