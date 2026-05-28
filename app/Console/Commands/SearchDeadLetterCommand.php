<?php

namespace App\Console\Commands;

use App\Domains\Search\Jobs\DeadLetterSearchIndexDocument;
use App\Domains\Search\Jobs\IndexSearchDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SearchDeadLetterCommand extends Command
{
    protected $signature = 'search:dead-letter
        {action=list : list or requeue}
        {--id= : Database queue job id to requeue}
        {--limit=10 : Maximum jobs to list}';

    protected $description = 'View and manually requeue search indexing dead-letter jobs.';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listJobs(),
            'requeue' => $this->requeueJob(),
            default => $this->invalidAction(),
        };
    }

    private function listJobs(): int
    {
        $jobs = DB::table('jobs')
            ->where('queue', $this->deadLetterQueue())
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->map(fn (object $job): array => $this->rowFor($job))
            ->all();

        if ($jobs === []) {
            $this->info('No search indexing dead-letter jobs found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Index', 'Document ID', 'Attempts', 'Failure'], $jobs);

        return self::SUCCESS;
    }

    private function requeueJob(): int
    {
        $id = $this->option('id');

        if (! is_numeric($id)) {
            $this->error('The --id option is required for requeue.');

            return self::INVALID;
        }

        $job = DB::table('jobs')
            ->where('queue', $this->deadLetterQueue())
            ->where('id', (int) $id)
            ->first();

        if ($job === null) {
            $this->error('Search indexing dead-letter job was not found.');

            return self::FAILURE;
        }

        $deadLetter = $this->deadLetterFrom($job);

        if (! $deadLetter instanceof DeadLetterSearchIndexDocument) {
            $this->error('Queue job is not a search indexing dead-letter document.');

            return self::FAILURE;
        }

        IndexSearchDocument::dispatch(
            index: $deadLetter->index,
            documentId: $deadLetter->documentId,
            document: $deadLetter->document,
        );

        DB::table('jobs')->where('id', $job->id)->delete();

        $this->info('Search indexing job requeued.');

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be list or requeue.');

        return self::INVALID;
    }

    /**
     * @return array<int, mixed>
     */
    private function rowFor(object $job): array
    {
        $deadLetter = $this->deadLetterFrom($job);

        if (! $deadLetter instanceof DeadLetterSearchIndexDocument) {
            return [$job->id, '-', '-', '-', 'Unsupported payload'];
        }

        return [
            $job->id,
            $deadLetter->index,
            $deadLetter->documentId,
            $deadLetter->attempts,
            $deadLetter->failure,
        ];
    }

    private function deadLetterFrom(object $job): ?DeadLetterSearchIndexDocument
    {
        $payload = json_decode($job->payload, true);

        if (! is_array($payload) || ! isset($payload['data']['command'])) {
            return null;
        }

        $command = unserialize($payload['data']['command'], [
            'allowed_classes' => [
                DeadLetterSearchIndexDocument::class,
            ],
        ]);

        return $command instanceof DeadLetterSearchIndexDocument ? $command : null;
    }

    private function deadLetterQueue(): string
    {
        return config('stockflow.search.indexing.dead_letter_queue');
    }
}
