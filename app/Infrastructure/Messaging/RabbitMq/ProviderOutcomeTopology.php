<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

class ProviderOutcomeTopology
{
    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->retryExchange(), 'topic', false, true, false);
        $channel->exchange_declare($this->retryReturnExchange(), 'topic', false, true, false);
        $channel->exchange_declare($this->deadLetterExchange(), 'topic', false, true, false);
        $channel->queue_bind($this->queue(), $this->retryReturnExchange(), '#');
        $channel->queue_bind($this->retryQueue(), $this->retryExchange(), '#');
        $channel->queue_bind($this->deadLetterQueue(), $this->deadLetterExchange(), '#');

        foreach ($this->exchanges() as $exchange => $routingKeys) {
            $channel->exchange_declare($exchange, 'topic', false, true, false);

            foreach ($routingKeys as $routingKey) {
                $channel->queue_bind($this->queue(), $exchange, $routingKey);
            }
        }
    }

    public function declareQueue(AMQPChannel $channel): void
    {
        $channel->queue_declare($this->queue(), false, true, false, false);
        $channel->queue_declare(
            $this->retryQueue(),
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-message-ttl' => $this->retryDelayMilliseconds(),
                'x-dead-letter-exchange' => $this->retryReturnExchange(),
            ]),
        );
        $channel->queue_declare($this->deadLetterQueue(), false, true, false, false);
    }

    public function queue(): string
    {
        return (string) config('stockflow.provider_saga.rabbitmq.outcomes_queue');
    }

    public function retryQueue(): string
    {
        return (string) config('stockflow.provider_saga.rabbitmq.outcomes_retry_queue');
    }

    public function deadLetterQueue(): string
    {
        return (string) config('stockflow.provider_saga.rabbitmq.outcomes_dead_letter_queue');
    }

    public function retryExchange(): string
    {
        return 'stockflow.market.provider.outcomes.retry';
    }

    public function retryReturnExchange(): string
    {
        return 'stockflow.market.provider.outcomes.retry-return';
    }

    public function deadLetterExchange(): string
    {
        return 'stockflow.market.provider.outcomes.dlx';
    }

    private function retryDelayMilliseconds(): int
    {
        return max(1, (int) config('stockflow.provider_saga.rabbitmq.outcomes_retry_delay_ms'));
    }

    /**
     * @return array<string, list<string>>
     */
    private function exchanges(): array
    {
        return [
            'stockflow.inventory' => [
                'inventory.reservation.confirmed.v1',
                'inventory.reservation.rejected.v1',
                'inventory.reservation.released.v1',
                'inventory.reservation.release_failed.v1',
            ],
            'stockflow.payment' => [
                'payment.authorization.approved.v1',
                'payment.authorization.declined.v1',
                'payment.capture.completed.v1',
                'payment.capture.failed.v1',
                'payment.refund.completed.v1',
                'payment.refund.failed.v1',
            ],
            'stockflow.delivery' => [
                'delivery.shipment.created.v1',
                'delivery.shipment.creation_failed.v1',
                'delivery.shipment.status_changed.v1',
                'delivery.shipment.cancelled.v1',
                'delivery.shipment.cancel_failed.v1',
            ],
        ];
    }
}
