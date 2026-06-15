<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\OutboxMessage;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class DomainEventRabbitMqPublisher
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly DomainEventTopology $topology,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $connection = $this->connections->create();
        $channel = $connection->channel();

        try {
            $channel->confirm_select();
            $channel->basic_publish(
                new AMQPMessage(
                    json_encode([
                        'event_name' => $message->event_name,
                        'schema_version' => $message->schema_version,
                        'aggregate_type' => $message->aggregate_type,
                        'aggregate_id' => $message->aggregate_id,
                        'payload' => $message->payload,
                    ], JSON_THROW_ON_ERROR),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'application_headers' => new AMQPTable([
                            'message_id' => $this->messageId($message),
                            'event_name' => $message->event_name,
                            'schema_version' => $message->schema_version,
                            'producer' => (string) config('stockflow.runtime.service_name'),
                            'retry_count' => max(0, $message->attempts - 1),
                            'occurred_at' => $message->created_at?->toISOString() ?? now()->toISOString(),
                        ]),
                    ],
                ),
                $this->topology->exchange(),
                $message->event_name,
            );
            $channel->wait_for_pending_acks($this->publisherConfirmTimeoutSeconds());
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    private function messageId(OutboxMessage $message): string
    {
        return (string) config('stockflow.runtime.service_name').':domain-outbox:'.$message->id;
    }

    private function publisherConfirmTimeoutSeconds(): int
    {
        return max(1, (int) config('stockflow.messaging.rabbitmq.publisher_confirm_timeout_seconds'));
    }
}
