<?php

namespace App\Infrastructure\Search;

use App\Domains\Search\Contracts\ProductSearch;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Throwable;

class ElasticsearchProductSearch implements ProductSearch
{
    public function __construct(
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker ??= app(CircuitBreaker::class);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    public function search(string $query, int $page, int $perPage): array
    {
        if (! $this->circuitBreaker->allows('elasticsearch')) {
            return $this->degradedResults($query, $page, $perPage);
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->asJson()
                ->post('/catalog_products/_search', [
                    'from' => ($page - 1) * $perPage,
                    'size' => $perPage,
                    'query' => [
                        'bool' => [
                            'must' => [[
                                'multi_match' => [
                                    'query' => $query,
                                    'fields' => ['name^3', 'sku^2', 'slug', 'description'],
                                    'fuzziness' => 'AUTO',
                                ],
                            ]],
                            'filter' => [[
                                'term' => ['status' => 'published'],
                            ]],
                        ],
                    ],
                ])
                ->throw()
                ->json();

            $this->circuitBreaker->recordSuccess('elasticsearch');

            return [
                'data' => collect(data_get($response, 'hits.hits', []))
                    ->map(fn (array $hit): array => (array) ($hit['_source'] ?? []))
                    ->values()
                    ->all(),
                'meta' => [
                    'query' => $query,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $this->totalHits($response),
                ],
            ];
        } catch (Throwable) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            return $this->degradedResults($query, $page, $perPage);
        }
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    private function degradedResults(string $query, int $page, int $perPage): array
    {
        return [
            'data' => [],
            'meta' => [
                'query' => $query,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'status' => 'degraded',
                'reason' => 'elasticsearch_unavailable',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function totalHits(?array $response): int
    {
        $total = data_get($response, 'hits.total');

        if (is_array($total)) {
            return (int) ($total['value'] ?? 0);
        }

        return (int) $total;
    }
}
