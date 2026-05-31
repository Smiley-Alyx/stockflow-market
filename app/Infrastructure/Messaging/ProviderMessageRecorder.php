<?php

namespace App\Infrastructure\Messaging;

use Illuminate\Support\Str;

class ProviderMessageRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $exchange,
        string $routingKey,
        string $correlationId,
        string $idempotencyKey,
        array $payload,
        ?string $causationId = null,
    ): ProviderOutboxMessage {
        $messageId = (string) Str::uuid();
        $causationId ??= $messageId;

        return ProviderOutboxMessage::query()->create([
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'idempotency_key' => $idempotencyKey,
            'headers' => [
                'message_id' => $messageId,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
                'idempotency_key' => $idempotencyKey,
                'schema_version' => 'v1',
                'occurred_at' => now()->toISOString(),
                'producer' => 'stockflow-market',
            ],
            'payload' => $payload,
            'status' => ProviderOutboxMessage::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }
}
