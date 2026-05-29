<?php

namespace App\Console\Commands;

use App\Domains\Search\DeadLetters\SearchIndexDeadLetter;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Jobs\DeleteSearchDocument;
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
        {--batch-size= : Maximum jobs to requeue per chunk}
        {--all : Requeue all jobs matching filters}
        {--dry-run : Show matching jobs without requeueing}
        {--force : Skip confirmation for bulk requeue}';

    protected $description = 'View and manually requeue search indexing dead-letter jobs.';

    public function __construct(private readonly SearchIndexDeadLetterStore $deadLetters)
    {
        parent::__construct();
    }

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

        if ($id !== null) {
            return $this->requeueSingleJob((int) $id);
        }

        $batchSize = $this->bulkBatchSize();

        if ($batchSize === null) {
            return self::INVALID;
        }

        $total = $this->countMatchingJobs($batchSize);

        if ($total === 0) {
            $this->error('Search indexing dead-letter job was not found.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $jobs = $this->matchingJobs(limit: max(1, (int) $this->option('limit')));

            $this->table(['ID', 'Index', 'Document ID', 'Attempts', 'Failure'], $jobs->map(fn (object $job): array => $this->rowFor($job))->all());
            $this->info('Dry run: '.$total.' search indexing job(s) matched.');

            return self::SUCCESS;
        }

        if ($total > 1 && ! $this->option('force')) {
            $confirmed = $this->confirm('Requeue '.$total.' search indexing dead-letter jobs?');

            if (! $confirmed) {
                $this->info('Requeue cancelled.');

                return self::SUCCESS;
            }
        }

        $requeued = $this->requeueMatchingJobs($batchSize, $total);

        if ($requeued === 1) {
            $this->info('Search indexing job requeued.');
        } else {
            $this->info('Search indexing jobs requeued: '.$requeued.'.');
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
        return [
            $job->id,
            $job->index,
            $job->documentId,
            $job->attempts,
            $job->failure,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function requeueSingleJob(int $id): int
    {
        $jobs = $this->matchingJobs($id);

        if ($jobs->isEmpty()) {
            $this->error('Search indexing dead-letter job was not found.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Index', 'Document ID', 'Attempts', 'Failure'], $jobs->map(fn (object $job): array => $this->rowFor($job))->all());
            $this->info('Dry run: 1 search indexing job(s) matched.');

            return self::SUCCESS;
        }

        $this->requeueJobs($jobs);
        $this->info('Search indexing job requeued.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, SearchIndexDeadLetter>
     */
    private function matchingJobs(?int $id = null, ?int $limit = null, int $afterId = 0): Collection
    {
        if ($id !== null) {
            $job = $this->deadLetters->find($id);

            return $job instanceof SearchIndexDeadLetter && $this->matchesFilters($job)
                ? collect([$job])
                : collect();
        }

        return $this->deadLetters->list(
            index: $this->option('index'),
            documentId: $this->option('document-id'),
            limit: $limit ?? max(1, (int) $this->option('limit')),
            afterId: $afterId,
        );
    }

    private function matchesFilters(SearchIndexDeadLetter $job): bool
    {
        if ($this->option('index') !== null && $job->index !== $this->option('index')) {
            return false;
        }

        if ($this->option('document-id') !== null && $job->documentId !== $this->option('document-id')) {
            return false;
        }

        return true;
    }

    private function bulkBatchSize(): ?int
    {
        $batchSize = $this->option('batch-size') === null
            ? (int) config('stockflow.search.indexing.requeue_batch_size')
            : (int) $this->option('batch-size');

        if ($batchSize < 1) {
            $this->error('The --batch-size option must be at least 1.');

            return null;
        }

        return min($batchSize, max(1, (int) config('stockflow.search.indexing.max_requeue_batch_size')));
    }

    private function countMatchingJobs(int $batchSize): int
    {
        $afterId = 0;
        $total = 0;

        do {
            $jobs = $this->matchingJobs(limit: $batchSize, afterId: $afterId);
            $total += $jobs->count();
            $afterId = $jobs->last()?->id ?? $afterId;
        } while ($jobs->isNotEmpty());

        return $total;
    }

    private function requeueMatchingJobs(int $batchSize, int $limit): int
    {
        $afterId = 0;
        $requeued = 0;

        while ($requeued < $limit) {
            $jobs = $this->matchingJobs(limit: min($batchSize, $limit - $requeued), afterId: $afterId);

            if ($jobs->isEmpty()) {
                break;
            }

            $afterId = $jobs->last()->id;
            $this->requeueJobs($jobs);
            $requeued += $jobs->count();
        }

        return $requeued;
    }

    /**
     * @param  Collection<int, SearchIndexDeadLetter>  $jobs
     */
    private function requeueJobs(Collection $jobs): void
    {
        DB::transaction(function () use ($jobs): void {
            foreach ($jobs as $job) {
                if ($job->operation === 'delete') {
                    DeleteSearchDocument::dispatch(
                        index: $job->index,
                        documentId: $job->documentId,
                    );
                } else {
                    IndexSearchDocument::dispatch(
                        index: $job->index,
                        documentId: $job->documentId,
                        document: $job->document,
                    );
                }

                $this->deadLetters->delete($job->id);

                $this->auditRequeue($job);
            }
        });
    }

    private function auditRequeue(SearchIndexDeadLetter $job): void
    {
        Log::channel(config('stockflow.search.indexing.requeue_audit_channel'))->info('search.dead_letter.requeued', [
            'event' => 'search.dead_letter.requeued',
            'service' => config('stockflow.runtime.service_name'),
            'dead_letter_id' => $job->id,
            'index' => $job->index,
            'document_id' => $job->documentId,
            'attempts' => $job->attempts,
        ]);
    }
}
