<?php

namespace App\Infrastructure\Search;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use App\Domains\Inventory\Read\InventoryReadService;
use App\Domains\Pricing\Read\PricingReadService;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ElasticsearchCatalogProductSearch implements CatalogProductSearch
{
    public function __construct(
        private readonly InventoryReadService $inventory,
        private readonly PricingReadService $pricing,
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker ??= app(CircuitBreaker::class);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function products(CatalogProductQuery $query): array
    {
        $filterableAttributes = $this->filterableAttributes($query);

        if (! $this->circuitBreaker->allows('elasticsearch')) {
            return $this->degradedResults($query);
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->asJson()
                ->post('/catalog_products/_search', [
                    'from' => ($query->page - 1) * $query->perPage,
                    'size' => $query->perPage,
                    'query' => $this->searchQuery($query),
                    'sort' => $this->sort($query->sort),
                    'aggs' => $this->aggregations($filterableAttributes),
                ])
                ->throw()
                ->json();

            $this->circuitBreaker->recordSuccess('elasticsearch');

            $products = collect(data_get($response, 'hits.hits', []))
                ->map(fn (array $hit): array => (array) ($hit['_source'] ?? []))
                ->values()
                ->all();

            return [
                'data' => $this->enrich($products, $query->cityCode),
                'meta' => [
                    'query' => $query->query,
                    'category' => $query->category,
                    'city_code' => $query->cityCode,
                    'sort' => $query->sort,
                    'current_page' => $query->page,
                    'per_page' => $query->perPage,
                    'total' => $this->totalHits($response),
                    'filters' => $this->availableFilters($response, $filterableAttributes),
                ],
            ];
        } catch (Throwable) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            return $this->degradedResults($query);
        }
    }

    /**
     * @return array<int, string>
     */
    private function filterableAttributes(CatalogProductQuery $query): array
    {
        $attributes = $query->category === null
            ? []
            : Category::query()
                ->where('slug', $query->category)
                ->where('is_active', true)
                ->value('filterable_attributes') ?? [];

        $attributes = collect(is_array($attributes) ? $attributes : json_decode((string) $attributes, true))
            ->map(fn (mixed $attribute): string => (string) $attribute)
            ->filter(fn (string $attribute): bool => preg_match('/^[a-zA-Z0-9_-]+$/', $attribute) === 1)
            ->unique()
            ->values()
            ->all();

        $unsupported = array_diff(array_keys($query->filters), $attributes);

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'filters' => ['Unsupported filters for the selected category: '.implode(', ', $unsupported).'.'],
            ]);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchQuery(CatalogProductQuery $query): array
    {
        $filters = [['term' => ['status' => 'published']]];

        if ($query->category !== null) {
            $filters[] = ['term' => ['category.slug.keyword' => $query->category]];
        }

        if ($query->brands !== []) {
            $filters[] = ['terms' => ['brand.slug.keyword' => $query->brands]];
        }

        if ($query->inStock !== null) {
            $filters[] = $query->cityCode === null
                ? ['term' => ['availability.in_stock' => $query->inStock]]
                : $this->cityAvailabilityFilter($query->cityCode, $query->inStock);
        }

        foreach ($query->filters as $name => $values) {
            $filters[] = ['terms' => ['filters.'.$name.'.keyword' => $values]];
        }

        return [
            'bool' => [
                'must' => $query->query === null ? [] : [[
                    'multi_match' => [
                        'query' => $query->query,
                        'fields' => ['name^3', 'sku^3', 'offers.sku^2', 'slug', 'description'],
                        'fuzziness' => 'AUTO',
                    ],
                ]],
                'filter' => $filters,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cityAvailabilityFilter(string $cityCode, bool $inStock): array
    {
        if ($inStock) {
            return ['term' => ['availability.city_codes.keyword' => $cityCode]];
        }

        return [
            'bool' => [
                'must_not' => [[
                    'term' => ['availability.city_codes.keyword' => $cityCode],
                ]],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sort(string $sort): array
    {
        return match ($sort) {
            'price_asc' => [['price.amount_minor' => ['order' => 'asc', 'missing' => '_last']], ['_id' => 'asc']],
            'price_desc' => [['price.amount_minor' => ['order' => 'desc', 'missing' => '_last']], ['_id' => 'asc']],
            'rating_asc' => [['rating' => 'asc'], ['_id' => 'asc']],
            'rating_desc' => [['rating' => 'desc'], ['_id' => 'asc']],
            default => [['published_at' => ['order' => 'desc', 'missing' => '_last']], ['_id' => 'asc']],
        };
    }

    /**
     * @param  array<int, string>  $attributes
     * @return array<string, array<string, mixed>>
     */
    private function aggregations(array $attributes): array
    {
        return collect($attributes)
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => ['terms' => ['field' => 'filters.'.$attribute.'.keyword', 'size' => 100]],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function enrich(array $products, ?string $cityCode): array
    {
        $productIds = collect($products)
            ->pluck('id')
            ->map(fn (mixed $productId): int => (int) $productId)
            ->all();
        $prices = $this->pricing->catalogPricesForProductIds($productIds, $cityCode);
        $availability = $this->inventory->availabilityForProductIds($productIds, $cityCode);

        return collect($products)
            ->map(function (array $product) use ($availability, $cityCode, $prices): array {
                $productId = (int) ($product['id'] ?? 0);
                $product['price'] = $prices[$productId] ?? null;
                $product['availability'] = array_merge(
                    $availability[$productId] ?? ['in_stock' => false, 'available_quantity' => 0],
                    ['city_code' => $cityCode],
                );

                return $product;
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $attributes
     * @return array<string, array<int, array{value: string, count: int}>>
     */
    private function availableFilters(array $response, array $attributes): array
    {
        return collect($attributes)
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => collect(data_get($response, 'aggregations.'.$attribute.'.buckets', []))
                    ->map(fn (array $bucket): array => [
                        'value' => (string) ($bucket['key'] ?? ''),
                        'count' => (int) ($bucket['doc_count'] ?? 0),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function degradedResults(CatalogProductQuery $query): array
    {
        return [
            'data' => [],
            'meta' => [
                'query' => $query->query,
                'category' => $query->category,
                'city_code' => $query->cityCode,
                'sort' => $query->sort,
                'current_page' => $query->page,
                'per_page' => $query->perPage,
                'total' => 0,
                'filters' => [],
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
