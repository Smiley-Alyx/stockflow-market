<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CatalogReadService
{
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
        $product = Product::query()
            ->with(['category', 'attributes'])
            ->where('slug', $slug)
            ->first();

        if (! $product instanceof Product) {
            return null;
        }

        return $this->productPayload($product);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function fetchProductList(int $page, int $perPage, ?string $categorySlug): array
    {
        $products = Product::query()
            ->with(['category', 'attributes'])
            ->where('status', 'published')
            ->whereHas('category', function ($query) use ($categorySlug): void {
                $query->where('is_active', true);

                if ($categorySlug !== null) {
                    $query->where('slug', $categorySlug);
                }
            })
            ->orderBy('name')
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => $products
                ->getCollection()
                ->map(fn (Product $product): array => $this->productPayload($product))
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
                'children' => $this->buildCategoryBranch($categories, $category->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'description' => $product->description,
            'status' => $product->status,
            'published_at' => $product->published_at?->toJSON(),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'attributes' => $product->attributes
                ->sortBy('name')
                ->map(fn ($attribute): array => [
                    'name' => $attribute->name,
                    'value' => $attribute->value,
                ])
                ->values()
                ->all(),
        ];
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
