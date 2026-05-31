<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\ProviderOutboxPublisher;
use Illuminate\Console\Command;

class PublishProviderOutboxCommand extends Command
{
    protected $signature = 'messaging:provider-outbox:publish {--limit=100} {--loop}';

    protected $description = 'Publish pending provider messages to RabbitMQ.';

    public function handle(ProviderOutboxPublisher $publisher): int
    {
        do {
            $published = $publisher->publishPending((int) $this->option('limit'));

            if (! $this->option('loop')) {
                $this->info("Published {$published} provider message(s).");

                return self::SUCCESS;
            }

            usleep($published === 0 ? 500_000 : 50_000);
        } while (true);
    }
}
