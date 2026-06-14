<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\DomainEventConsumer;
use Illuminate\Console\Command;

class ConsumeDomainEventsCommand extends Command
{
    protected $signature = 'messaging:domain-events:consume';

    protected $description = 'Consume domain events from RabbitMQ.';

    public function handle(DomainEventConsumer $consumer): int
    {
        $consumer->consume();

        return self::SUCCESS;
    }
}
