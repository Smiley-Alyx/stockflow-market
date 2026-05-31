<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class ProviderOutcomeConsumer
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly ProviderOutcomeTopology $topology,
        private readonly ProviderOutcomeProcessor $processor,
    ) {}

    public function consume(): void
    {
        $this->registerSignalHandlers();

        $connection = $this->connections->create();
        $channel = $connection->channel();

        try {
            $this->topology->declareQueue($channel);
            $this->topology->declare($channel);
            $channel->basic_qos(0, 10, false);
            $channel->basic_consume(
                queue: $this->topology->queue(),
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: function (AMQPMessage $message): void {
                    try {
                        $headers = $message->get('application_headers')->getNativeData();
                        $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

                        $this->processor->process((string) $message->getRoutingKey(), $headers, $payload);
                        $message->ack();
                    } catch (\Throwable) {
                        $message->nack(requeue: true);
                    }
                },
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
