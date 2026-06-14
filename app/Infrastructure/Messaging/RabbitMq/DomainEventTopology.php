<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

class DomainEventTopology
{
    public function declarePublisher(AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->exchange(), 'topic', false, true, false);
    }

    public function declareConsumer(AMQPChannel $channel): void
    {
        $this->declarePublisher($channel);
        $channel->exchange_declare($this->retryExchange(), 'topic', false, true, false);
        $channel->exchange_declare($this->retryReturnExchange(), 'topic', false, true, false);
        $channel->exchange_declare($this->deadLetterExchange(), 'topic', false, true, false);

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

        $channel->queue_bind($this->queue(), $this->exchange(), '#');
        $channel->queue_bind($this->queue(), $this->retryReturnExchange(), '#');
        $channel->queue_bind($this->retryQueue(), $this->retryExchange(), '#');
        $channel->queue_bind($this->deadLetterQueue(), $this->deadLetterExchange(), '#');
    }

    public function exchange(): string
    {
        return (string) config('stockflow.messaging.rabbitmq.exchange');
    }

    public function queue(): string
    {
        return (string) config('stockflow.messaging.rabbitmq.queue');
    }

    public function retryQueue(): string
    {
        return (string) config('stockflow.messaging.rabbitmq.retry_queue');
    }

    public function deadLetterQueue(): string
    {
        return (string) config('stockflow.messaging.rabbitmq.dead_letter_queue');
    }

    public function retryExchange(): string
    {
        return $this->exchange().'.retry';
    }

    public function retryReturnExchange(): string
    {
        return $this->exchange().'.retry-return';
    }

    public function deadLetterExchange(): string
    {
        return $this->exchange().'.dlx';
    }

    private function retryDelayMilliseconds(): int
    {
        return max(1, (int) config('stockflow.messaging.rabbitmq.retry_delay_ms'));
    }
}
