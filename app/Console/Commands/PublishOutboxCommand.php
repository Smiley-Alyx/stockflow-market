<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\DomainEventPublisher;
use Illuminate\Console\Command;

class PublishOutboxCommand extends Command
{
    protected $signature = 'messaging:outbox:publish {--limit=100 : Maximum pending messages to publish} {--loop : Keep polling the outbox}';

    protected $description = 'Publish pending domain events from the transactional outbox.';

    public function handle(DomainEventPublisher $publisher): int
    {
        do {
            $published = $publisher->publishPending((int) $this->option('limit'));

            if (! $this->option('loop')) {
                $this->info("Published {$published} outbox message(s).");

                return self::SUCCESS;
            }

            usleep($published === 0 ? 500_000 : 50_000);
        } while (true);
    }
}
