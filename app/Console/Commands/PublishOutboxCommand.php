<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\DomainEventPublisher;
use Illuminate\Console\Command;

class PublishOutboxCommand extends Command
{
    protected $signature = 'messaging:outbox:publish {--limit=100 : Maximum pending messages to publish}';

    protected $description = 'Publish pending domain events from the transactional outbox.';

    public function handle(DomainEventPublisher $publisher): int
    {
        $published = $publisher->publishPending((int) $this->option('limit'));

        $this->info("Published {$published} outbox message(s).");

        return self::SUCCESS;
    }
}
