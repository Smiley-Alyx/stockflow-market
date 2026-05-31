<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeConsumer;
use Illuminate\Console\Command;

class ConsumeProviderOutcomesCommand extends Command
{
    protected $signature = 'messaging:provider-outcomes:consume';

    protected $description = 'Consume provider outcome messages from RabbitMQ.';

    public function handle(ProviderOutcomeConsumer $consumer): int
    {
        $consumer->consume();

        return self::SUCCESS;
    }
}
