<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;

class ProviderOutcomeTopology
{
    public function declare(AMQPChannel $channel): void
    {
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
    }

    public function queue(): string
    {
        return (string) config('stockflow.provider_saga.rabbitmq.outcomes_queue');
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
