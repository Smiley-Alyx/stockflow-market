<?php

namespace App\Infrastructure\Observability;

use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class PrometheusExporter
{
    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly SearchIndexDeadLetterStore $deadLetters,
    ) {}

    public function export(): string
    {
        $lines = [
            '# HELP stockflow_http_request_duration_seconds HTTP request latency by route endpoint.',
            '# TYPE stockflow_http_request_duration_seconds histogram',
        ];

        foreach ($this->metrics->series() as $series) {
            if ($series['name'] !== 'stockflow_http_request_duration_seconds') {
                continue;
            }

            $labels = $series['labels'];
            $count = $this->metrics->value('stockflow_http_request_duration_seconds_count', $labels);
            $sum = $this->metrics->value('stockflow_http_request_duration_seconds_sum', $labels) / 1_000_000;

            foreach ($this->latencyBucketLabels() as $bucket) {
                $bucketLabels = $labels + ['le' => $bucket];
                $lines[] = $this->sample('stockflow_http_request_duration_seconds_bucket', $bucketLabels, $this->metrics->value('stockflow_http_request_duration_seconds_bucket', $bucketLabels));
            }

            $lines[] = $this->sample('stockflow_http_request_duration_seconds_count', $labels, $count);
            $lines[] = $this->sample('stockflow_http_request_duration_seconds_sum', $labels, $sum);
        }

        $lines[] = '# HELP stockflow_queue_depth Pending jobs by queue.';
        $lines[] = '# TYPE stockflow_queue_depth gauge';
        foreach ($this->queueDepths() as $queue => $depth) {
            $lines[] = $this->sample('stockflow_queue_depth', ['queue' => $queue], $depth);
        }

        $lines[] = '# HELP stockflow_search_dead_letter_count Search indexing dead-letter records.';
        $lines[] = '# TYPE stockflow_search_dead_letter_count gauge';
        $lines[] = $this->sample('stockflow_search_dead_letter_count', ['queue' => config('stockflow.search.indexing.dead_letter_queue')], $this->deadLetters->count());

        $lines[] = '# HELP stockflow_inventory_reservation_conflicts_total Inventory reservation conflicts by reason.';
        $lines[] = '# TYPE stockflow_inventory_reservation_conflicts_total counter';
        $lines = array_merge($lines, $this->counterSamples('stockflow_inventory_reservation_conflicts_total'));

        $lines[] = '# HELP stockflow_search_indexing_failures_total Elasticsearch indexing failures by operation and index.';
        $lines[] = '# TYPE stockflow_search_indexing_failures_total counter';
        $lines = array_merge($lines, $this->counterSamples('stockflow_search_indexing_failures_total'));

        $lines[] = '# HELP stockflow_checkout_sagas_total Checkout provider sagas by outcome.';
        $lines[] = '# TYPE stockflow_checkout_sagas_total counter';
        $lines = array_merge($lines, $this->counterSamples('stockflow_checkout_sagas_total'));

        $lines[] = '# HELP stockflow_checkout_saga_compensations_total Checkout saga compensations by operation and outcome.';
        $lines[] = '# TYPE stockflow_checkout_saga_compensations_total counter';
        $lines = array_merge($lines, $this->counterSamples('stockflow_checkout_saga_compensations_total'));

        $lines[] = '# HELP stockflow_messaging_stale_claim_recoveries_total Recovered stale messaging claims by store.';
        $lines[] = '# TYPE stockflow_messaging_stale_claim_recoveries_total counter';
        $lines = array_merge($lines, $this->counterSamples('stockflow_messaging_stale_claim_recoveries_total'));

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string>
     */
    private function latencyBucketLabels(): array
    {
        return ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5', '+Inf'];
    }

    /**
     * @return array<string, int>
     */
    private function queueDepths(): array
    {
        $queues = array_unique([
            'default',
            (string) config('stockflow.search.indexing.queue'),
        ]);

        return collect($queues)
            ->mapWithKeys(fn (string $queue): array => [$queue => $this->queueDepth($queue)])
            ->all();
    }

    private function queueDepth(string $queue): int
    {
        return match (Queue::getDefaultDriver()) {
            'redis' => (int) Redis::connection(config('queue.connections.redis.connection'))->llen('queues:'.$queue),
            'database' => (int) DB::table(config('queue.connections.database.table'))->where('queue', $queue)->count(),
            default => 0,
        };
    }

    /**
     * @return array<int, string>
     */
    private function counterSamples(string $name): array
    {
        return collect($this->metrics->series())
            ->filter(fn (array $series): bool => $series['name'] === $name)
            ->map(fn (array $series): string => $this->sample($name, $series['labels'], $this->metrics->value($name, $series['labels'])))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels, int|float $value): string
    {
        return $name.$this->formatLabels($labels).' '.$value;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels);

        return '{'.collect($labels)
            ->map(fn (string $value, string $key): string => $key.'="'.$this->escapeLabel($value).'"')
            ->implode(',').'}';
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
