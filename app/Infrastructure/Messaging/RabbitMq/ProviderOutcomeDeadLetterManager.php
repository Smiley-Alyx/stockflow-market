<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class ProviderOutcomeDeadLetterManager
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly ProviderOutcomeTopology $topology,
    ) {}

    /**
     * @return array<int, array{routing_key: string, headers: array<string, mixed>, payload: mixed}>
     */
    public function list(int $limit = 100): array
    {
        $connection = $this->connections->create();
        $channel = $connection->channel();
        $messages = [];

        try {
            while (count($messages) < max(1, $limit)) {
                $message = $channel->basic_get($this->topology->deadLetterQueue());

                if ($message === null) {
                    break;
                }

                $messages[] = [
                    'routing_key' => (string) $message->getRoutingKey(),
                    'headers' => $this->headers($message),
                    'payload' => json_decode($message->getBody(), true),
                ];
                $held[] = $message;
            }

            return $messages;
        } finally {
            foreach ($held ?? [] as $message) {
                $message->reject(requeue: true);
            }

            $channel->close();
            $connection->close();
        }
    }

    public function requeue(int $limit = 100): int
    {
        $connection = $this->connections->create();
        $channel = $connection->channel();
        $messages = [];

        try {
            $channel->confirm_select();

            while (count($messages) < max(1, $limit)) {
                $message = $channel->basic_get($this->topology->deadLetterQueue());

                if ($message === null) {
                    break;
                }

                $headers = $this->headers($message);
                $headers['retry_count'] = 0;

                $channel->basic_publish(
                    new AMQPMessage(
                        $message->getBody(),
                        [
                            'content_type' => 'application/json',
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'application_headers' => new AMQPTable($headers),
                        ],
                    ),
                    $this->topology->retryReturnExchange(),
                    (string) $message->getRoutingKey(),
                );
                $messages[] = $message;
            }

            $channel->wait_for_pending_acks($this->publisherConfirmTimeoutSeconds());

            foreach ($messages as $message) {
                $message->ack();
            }

            return count($messages);
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(AMQPMessage $message): array
    {
        if (! $message->has('application_headers')) {
            return [];
        }

        return $message->get('application_headers')->getNativeData();
    }

    private function publisherConfirmTimeoutSeconds(): int
    {
        return max(1, (int) config('stockflow.provider_saga.outbox.publisher_confirm_timeout_seconds'));
    }
}
