<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Catalog\Models\CatalogProductProjection;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Services\CatalogUrlService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CatalogReadService
{
    public function __construct(
        private readonly CatalogUrlService $urls,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function productBySlug(string $slug): ?array
    {
        return Cache::remember(
            CatalogCacheKeys::productBySlug($slug),
            config('stockflow.catalog.cache.product_ttl_seconds'),
            fn () => $this->fetchProductBySlug($slug),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeCategoryTree(): array
    {
        return Cache::remember(
            CatalogCacheKeys::activeCategoryTree(),
            config('stockflow.catalog.cache.category_tree_ttl_seconds'),
            fn () => $this->fetchActiveCategoryTree(),
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function productList(int $page, int $perPage, ?string $categorySlug = null): array
    {
        return Cache::remember(
            CatalogCacheKeys::productList($page, $perPage, $categorySlug),
            config('stockflow.catalog.cache.product_ttl_seconds'),
            fn () => $this->fetchProductList($page, $perPage, $categorySlug),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductBySlug(string $slug): ?array
    {
        /** @var CatalogProductProjection|null $projection */
        $projection = CatalogProductProjection::query()
            ->where('slug', $slug)
            ->first();

        return $projection?->payload;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function fetchProductList(int $page, int $perPage, ?string $categorySlug): array
    {
        $products = CatalogProductProjection::query()
            ->where('status', 'published')
            ->where('category_is_active', true)
            ->when($categorySlug !== null, fn ($query) => $query->where('category_slug', $categorySlug))
            ->orderBy('name')
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => $products
                ->getCollection()
                ->map(fn (CatalogProductProjection $projection): array => $projection->payload)
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($products),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActiveCategoryTree(): array
    {
        $categories = Category::query()
            ->with('imageFile')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->buildCategoryBranch($categories, null);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryBranch(Collection $categories, ?int $parentId): array
    {
        return $categories
            ->where('parent_id', $parentId)
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image_url' => $category->imageFile?->url(),
                'filterable_attributes' => $category->filterable_attributes ?? [],
                'url' => $this->urls->categoryUrl($category),
                'children' => $this->buildCategoryBranch($categories, $category->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $products): array
    {
        return [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ];
    }
}
