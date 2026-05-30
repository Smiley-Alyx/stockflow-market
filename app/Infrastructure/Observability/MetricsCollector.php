<?php

namespace App\Infrastructure\Observability;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class MetricsCollector
{
    private const INDEX_KEY = 'stockflow:metrics:index';

    /**
     * @var array<float>
     */
    private array $latencyBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0];

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @param  array<string, string>  $labels
     */
    public function increment(string $name, array $labels = [], int $by = 1): void
    {
        $this->track($name, 'counter', $labels);
        $this->cache->increment($this->seriesKey($name, $labels), $by);
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function observeHttpLatency(string $method, string $endpoint, int $status, float $seconds): void
    {
        $labels = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => (string) $status,
        ];

        $this->track('stockflow_http_request_duration_seconds', 'histogram', $labels);
        $this->cache->increment($this->seriesKey('stockflow_http_request_duration_seconds_count', $labels));
        $this->cache->increment($this->seriesKey('stockflow_http_request_duration_seconds_sum', $labels), (int) round($seconds * 1_000_000));

        foreach ($this->latencyBuckets as $bucket) {
            if ($seconds <= $bucket) {
                $this->cache->increment($this->seriesKey('stockflow_http_request_duration_seconds_bucket', $labels + [
                    'le' => $this->bucketLabel($bucket),
                ]));
            }
        }

        $this->cache->increment($this->seriesKey('stockflow_http_request_duration_seconds_bucket', $labels + [
            'le' => '+Inf',
        ]));
    }

    /**
     * @return array<int, array{name: string, type: string, labels: array<string, string>}>
     */
    public function series(): array
    {
        $series = $this->cache->get(self::INDEX_KEY, []);

        return is_array($series) ? array_values($series) : [];
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function value(string $name, array $labels = []): int
    {
        return (int) $this->cache->get($this->seriesKey($name, $labels), 0);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function track(string $name, string $type, array $labels): void
    {
        $series = $this->cache->get(self::INDEX_KEY, []);
        $key = $this->seriesId($name, $labels);

        if (isset($series[$key])) {
            return;
        }

        $series[$key] = [
            'name' => $name,
            'type' => $type,
            'labels' => $labels,
        ];

        $this->cache->forever(self::INDEX_KEY, $series);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function seriesKey(string $name, array $labels): string
    {
        return 'stockflow:metrics:series:'.$this->seriesId($name, $labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function seriesId(string $name, array $labels): string
    {
        ksort($labels);

        return sha1($name.':'.json_encode($labels, JSON_THROW_ON_ERROR));
    }

    private function bucketLabel(float $bucket): string
    {
        return rtrim(rtrim(number_format($bucket, 3, '.', ''), '0'), '.');
    }
}
