<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
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
            "catalog:products:slug:{$slug}",
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
            'catalog:categories:tree:active',
            config('stockflow.catalog.cache.category_tree_ttl_seconds'),
            fn () => $this->fetchActiveCategoryTree(),
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
}
