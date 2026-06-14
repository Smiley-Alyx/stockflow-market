<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnectionFactory
{
    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: (string) config('stockflow.dependencies.rabbitmq.host'),
            port: (int) config('stockflow.dependencies.rabbitmq.port'),
            user: (string) config('stockflow.dependencies.rabbitmq.user'),
            password: (string) config('stockflow.dependencies.rabbitmq.password'),
            vhost: (string) config('stockflow.dependencies.rabbitmq.vhost'),
        );
    }
}
