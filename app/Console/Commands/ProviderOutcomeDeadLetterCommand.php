<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeDeadLetterManager;
use Illuminate\Console\Command;

class ProviderOutcomeDeadLetterCommand extends Command
{
    protected $signature = 'messaging:provider-outcomes:dead-letter
        {action=list : Action to execute: list or requeue}
        {--limit=100 : Maximum number of messages to inspect or requeue}';

    protected $description = 'View and manually requeue provider outcome dead-letter messages.';

    public function handle(ProviderOutcomeDeadLetterManager $deadLetters): int
    {
        return match ($this->argument('action')) {
            'list' => $this->showMessages($deadLetters),
            'requeue' => $this->requeue($deadLetters),
            default => $this->invalidAction(),
        };
    }

    private function showMessages(ProviderOutcomeDeadLetterManager $deadLetters): int
    {
        $messages = $deadLetters->list($this->limit());

        if ($messages === []) {
            $this->info('No provider outcome dead-letter messages found.');

            return self::SUCCESS;
        }

        $this->table(
            ['routing_key', 'message_id', 'correlation_id', 'retry_count', 'payload'],
            collect($messages)->map(fn (array $message): array => [
                $message['routing_key'],
                $message['headers']['message_id'] ?? '',
                $message['headers']['correlation_id'] ?? '',
                $message['headers']['retry_count'] ?? '',
                json_encode($message['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function requeue(ProviderOutcomeDeadLetterManager $deadLetters): int
    {
        $requeued = $deadLetters->requeue($this->limit());
        $this->info("Requeued {$requeued} provider outcome dead-letter message(s).");

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be one of: list, requeue.');

        return self::FAILURE;
    }

    private function limit(): int
    {
        return max(1, (int) $this->option('limit'));
    }
}
