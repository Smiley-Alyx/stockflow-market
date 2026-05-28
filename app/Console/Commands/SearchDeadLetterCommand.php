<?php

namespace App\Console\Commands;

use App\Domains\Search\Jobs\DeadLetterSearchIndexDocument;
use App\Domains\Search\Jobs\IndexSearchDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchDeadLetterCommand extends Command
{
    protected $signature = 'search:dead-letter
        {action=list : list or requeue}
        {--id= : Database queue job id to requeue}
        {--index= : Search index filter}
        {--document-id= : Search document id filter}
        {--limit=10 : Maximum jobs to list}
        {--all : Requeue all jobs matching filters}
        {--dry-run : Show matching jobs without requeueing}
        {--force : Skip confirmation for bulk requeue}';

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
        $jobs = $this->matchingJobs()
            ->take(max(1, (int) $this->option('limit')))
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

        if ($id !== null && ! is_numeric($id)) {
            $this->error('The --id option must be a numeric database queue job id.');

            return self::INVALID;
        }

        if ($id === null && ! $this->option('all')) {
            $this->error('The --id option or --all flag is required for requeue.');

            return self::INVALID;
        }

        $jobs = $this->matchingJobs($id === null ? null : (int) $id);

        if ($jobs->isEmpty()) {
            $this->error('Search indexing dead-letter job was not found.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Index', 'Document ID', 'Attempts', 'Failure'], $jobs->map(fn (object $job): array => $this->rowFor($job))->all());
            $this->info('Dry run: '.$jobs->count().' search indexing job(s) matched.');

            return self::SUCCESS;
        }

        if ($jobs->count() > 1 && ! $this->option('force')) {
            $confirmed = $this->confirm('Requeue '.$jobs->count().' search indexing dead-letter jobs?');

            if (! $confirmed) {
                $this->info('Requeue cancelled.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($jobs): void {
            foreach ($jobs as $job) {
                $deadLetter = $this->deadLetterFrom($job);

                if (! $deadLetter instanceof DeadLetterSearchIndexDocument) {
                    continue;
                }

                IndexSearchDocument::dispatch(
                    index: $deadLetter->index,
                    documentId: $deadLetter->documentId,
                    document: $deadLetter->document,
                );

                DB::table('jobs')->where('id', $job->id)->delete();

                Log::info('Search indexing dead-letter job requeued.', [
                    'queue_job_id' => $job->id,
                    'index' => $deadLetter->index,
                    'document_id' => $deadLetter->documentId,
                    'attempts' => $deadLetter->attempts,
                ]);
            }
        });

        if ($jobs->count() === 1) {
            $this->info('Search indexing job requeued.');
        } else {
            $this->info('Search indexing jobs requeued: '.$jobs->count().'.');
        }

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

    /**
     * @return Collection<int, object>
     */
    private function matchingJobs(?int $id = null): Collection
    {
        $jobs = DB::table('jobs')
            ->where('queue', $this->deadLetterQueue())
            ->when($id !== null, fn ($query) => $query->where('id', $id))
            ->orderBy('id')
            ->get();

        return $jobs->filter(function (object $job): bool {
            $deadLetter = $this->deadLetterFrom($job);

            if (! $deadLetter instanceof DeadLetterSearchIndexDocument) {
                return false;
            }

            if ($this->option('index') !== null && $deadLetter->index !== $this->option('index')) {
                return false;
            }

            if ($this->option('document-id') !== null && $deadLetter->documentId !== $this->option('document-id')) {
                return false;
            }

            return true;
        })->values();
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
