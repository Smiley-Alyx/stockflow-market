<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnectionFactory
{
    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: (string) config('stockflow.provider_saga.rabbitmq.host'),
            port: (int) config('stockflow.provider_saga.rabbitmq.port'),
            user: (string) config('stockflow.provider_saga.rabbitmq.user'),
            password: (string) config('stockflow.provider_saga.rabbitmq.password'),
            vhost: (string) config('stockflow.provider_saga.rabbitmq.vhost'),
        );
    }
}
