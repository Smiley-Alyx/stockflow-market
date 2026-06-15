<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class DomainEventConsumer
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly DomainEventTopology $topology,
        private readonly DomainEventProcessor $processor,
    ) {}

    public function consume(): void
    {
        $this->registerSignalHandlers();

        $connection = $this->connections->create();
        $channel = $connection->channel();

        try {
            $channel->confirm_select();
            $channel->basic_qos(0, 10, false);
            $channel->basic_consume(
                queue: $this->topology->queue(),
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: fn (AMQPMessage $message) => $this->consumeMessage($message),
            );

            while ($channel->is_consuming() && ! $this->shouldStop) {
                try {
                    $channel->wait(null, false, 5);
                } catch (AMQPTimeoutException) {
                    // Poll again so signal handlers can stop an idle consumer.
                }
            }
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    public function consumeMessage(AMQPMessage $message): void
    {
        try {
            $envelope = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $this->processor->process($this->headers($message), $envelope);
            $message->ack();
        } catch (Throwable) {
            try {
                $this->retryOrDeadLetter($message);
                $message->ack();
            } catch (Throwable) {
                $message->nack(requeue: true);
            }
        }
    }

    private function retryOrDeadLetter(AMQPMessage $message): void
    {
        $headers = $this->headers($message);
        $retryCount = (int) ($headers['retry_count'] ?? 0);
        $headers['retry_count'] = $retryCount + 1;
        $exchange = $retryCount < $this->maxRetryCount()
            ? $this->topology->retryExchange()
            : $this->topology->deadLetterExchange();

        $message->getChannel()->basic_publish(
            new AMQPMessage(
                $message->getBody(),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => new AMQPTable($headers),
                ],
            ),
            $exchange,
            (string) $message->getRoutingKey(),
        );
        $message->getChannel()->wait_for_pending_acks($this->publisherConfirmTimeoutSeconds());
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

    private function maxRetryCount(): int
    {
        return max(0, (int) config('stockflow.messaging.rabbitmq.max_retry_count'));
    }

    private function publisherConfirmTimeoutSeconds(): int
    {
        return max(1, (int) config('stockflow.messaging.rabbitmq.publisher_confirm_timeout_seconds'));
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
    }
}
