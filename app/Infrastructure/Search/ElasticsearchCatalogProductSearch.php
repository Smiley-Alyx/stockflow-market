<?php

namespace App\Infrastructure\Search;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use App\Domains\Catalog\Services\CatalogUrlService;
use App\Domains\Inventory\Read\InventoryReadService;
use App\Domains\Pricing\Read\PricingReadService;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ElasticsearchCatalogProductSearch implements CatalogProductSearch
{
    public function __construct(
        private readonly InventoryReadService $inventory,
        private readonly PricingReadService $pricing,
        private readonly CatalogUrlService $urls,
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker ??= app(CircuitBreaker::class);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function products(CatalogProductQuery $query): array
    {
        $category = $this->urls->resolveCategory($query->categoryPath, $query->category);
        $filterableAttributes = $this->filterableAttributes($query, $category);

        if (! $this->circuitBreaker->allows('elasticsearch')) {
            return $this->degradedResults($query, $category);
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/'))
                ->timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->asJson()
                ->post('/catalog_products/_search', array_filter([
                    'from' => ($query->page - 1) * $query->perPage,
                    'size' => $query->perPage,
                    'query' => $this->searchQuery($query, $category),
                    'post_filter' => $this->priceFilter($query),
                    'sort' => $this->sort($query->sort),
                    'aggs' => $this->aggregations($filterableAttributes),
                ], fn (mixed $value): bool => $value !== null))
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
                    'category_path' => $category === null ? null : $this->urls->categoryPath($category),
                    'canonical_url' => $category === null ? '/catalog/' : $this->urls->categoryUrl($category),
                    'breadcrumbs' => $category === null ? [] : $this->urls->breadcrumbs($category),
                    'filter_url' => $this->urls->filterUrl($category, $query->filters, $query->priceFrom, $query->priceTo),
                    'price_range' => $this->priceRange($response, $category, $query),
                    'city_code' => $query->cityCode,
                    'sort' => $query->sort,
                    'current_page' => $query->page,
                    'per_page' => $query->perPage,
                    'total' => $this->totalHits($response),
                    'filters' => $this->availableFilters($response, $filterableAttributes, $category, $query),
                ],
            ];
        } catch (Throwable) {
            $this->circuitBreaker->recordFailure('elasticsearch');

            return $this->degradedResults($query, $category);
        }
    }

    /**
     * @return array<int, string>
     */
    private function filterableAttributes(CatalogProductQuery $query, ?Category $category): array
    {
        $attributes = $this->categoryTree($category)
            ->pluck('filterable_attributes')
            ->flatten()
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
    private function searchQuery(CatalogProductQuery $query, ?Category $category): array
    {
        $filters = [['term' => ['status' => 'published']]];

        if ($category !== null) {
            $filters[] = $this->categoryFilter($category);
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
    private function categoryFilter(Category $category): array
    {
        $path = $this->urls->categoryPath($category);

        if (! $category->children()->where('is_active', true)->exists()) {
            return ['term' => ['category.path.keyword' => $path]];
        }

        return [
            'bool' => [
                'should' => [
                    ['term' => ['category.path.keyword' => $path]],
                    ['prefix' => ['category.path.keyword' => $path.'/']],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @return Collection<int, Category>
     */
    private function categoryTree(?Category $category): Collection
    {
        if (! $category instanceof Category) {
            return collect();
        }

        $categories = collect([$category]);
        $parentIds = collect([$category->id]);

        while ($parentIds->isNotEmpty()) {
            $children = Category::query()
                ->whereIn('parent_id', $parentIds)
                ->where('is_active', true)
                ->get();
            $categories = $categories->merge($children);
            $parentIds = $children->pluck('id');
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function priceFilter(CatalogProductQuery $query): ?array
    {
        if ($query->priceFrom === null && $query->priceTo === null) {
            return null;
        }

        return ['range' => ['price.amount_minor' => array_filter([
            'gte' => $query->priceFrom,
            'lte' => $query->priceTo,
        ], fn (?int $value): bool => $value !== null)]];
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
            'price_asc' => [['price.amount_minor' => ['order' => 'asc', 'missing' => '_last']], ['id' => 'asc']],
            'price_desc' => [['price.amount_minor' => ['order' => 'desc', 'missing' => '_last']], ['id' => 'asc']],
            'rating_asc' => [['rating' => 'asc'], ['id' => 'asc']],
            'rating_desc' => [['rating' => 'desc'], ['id' => 'asc']],
            default => [['published_at' => ['order' => 'desc', 'missing' => '_last']], ['id' => 'asc']],
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
            ->merge([
                'price_min' => ['min' => ['field' => 'price.amount_minor']],
                'price_max' => ['max' => ['field' => 'price.amount_minor']],
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
    private function availableFilters(array $response, array $attributes, ?Category $category, CatalogProductQuery $query): array
    {
        return collect($attributes)
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => collect(data_get($response, 'aggregations.'.$attribute.'.buckets', []))
                    ->map(function (array $bucket) use ($attribute, $category, $query): array {
                        $value = (string) ($bucket['key'] ?? '');
                        $filters = $query->filters;
                        $filters[$attribute] = collect($filters[$attribute] ?? [])
                            ->push($value)
                            ->unique()
                            ->values()
                            ->all();

                        return [
                            'value' => $value,
                            'count' => (int) ($bucket['doc_count'] ?? 0),
                            'url' => $this->urls->filterUrl($category, $filters, $query->priceFrom, $query->priceTo),
                        ];
                    })
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array{min: int|null, max: int|null, selected_min: int|null, selected_max: int|null, url: string, url_template: string}
     */
    private function priceRange(array $response, ?Category $category, CatalogProductQuery $query): array
    {
        $min = data_get($response, 'aggregations.price_min.value');
        $max = data_get($response, 'aggregations.price_max.value');

        return [
            'min' => $min === null ? null : (int) floor($min),
            'max' => $max === null ? null : (int) ceil($max),
            'selected_min' => $query->priceFrom,
            'selected_max' => $query->priceTo,
            'url' => $this->urls->filterUrl($category, $query->filters, $query->priceFrom, $query->priceTo),
            'url_template' => $this->urls->priceUrlTemplate($category, $query->filters),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function degradedResults(CatalogProductQuery $query, ?Category $category): array
    {
        return [
            'data' => [],
            'meta' => [
                'query' => $query->query,
                'category' => $query->category,
                'category_path' => $category === null ? $query->categoryPath : $this->urls->categoryPath($category),
                'canonical_url' => $category === null ? '/catalog/' : $this->urls->categoryUrl($category),
                'breadcrumbs' => $category === null ? [] : $this->urls->breadcrumbs($category),
                'filter_url' => $this->urls->filterUrl($category, $query->filters, $query->priceFrom, $query->priceTo),
                'price_range' => [
                    'min' => null,
                    'max' => null,
                    'selected_min' => $query->priceFrom,
                    'selected_max' => $query->priceTo,
                    'url' => $this->urls->filterUrl($category, $query->filters, $query->priceFrom, $query->priceTo),
                    'url_template' => $this->urls->priceUrlTemplate($category, $query->filters),
                ],
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
