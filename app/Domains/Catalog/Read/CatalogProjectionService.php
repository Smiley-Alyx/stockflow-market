<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Catalog\Models\CatalogProductProjection;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use App\Domains\Catalog\Services\CatalogUrlService;
use App\Domains\Inventory\Read\InventoryReadService;
use Illuminate\Support\Collection;

class CatalogProjectionService
{
    public function __construct(
        private readonly InventoryReadService $inventory,
        private readonly CatalogUrlService $urls,
    ) {}

    public function syncProduct(Product|int $product): void
    {
        $productId = $product instanceof Product ? $product->id : $product;

        /** @var Product|null $fresh */
        $fresh = Product::query()
            ->with(['brand.logoFile', 'category.imageFile', 'imageFile', 'attributes', 'offers.imageFile'])
            ->find($productId);

        if (! $fresh instanceof Product) {
            $this->deleteProduct($productId);

            return;
        }

        $availability = $this->inventory->availabilityForProductIds([$fresh->id])[$fresh->id] ?? $this->emptyAvailability();
        $payload = $this->productPayload($fresh, $availability);

        CatalogProductProjection::query()->updateOrCreate(
            ['product_id' => $fresh->id],
            [
                'category_id' => $fresh->category_id,
                'category_slug' => $fresh->category?->slug ?? '',
                'category_is_active' => (bool) $fresh->category?->is_active,
                'name' => $fresh->name,
                'slug' => $fresh->slug,
                'sku' => $fresh->sku,
                'status' => $fresh->status,
                'in_stock' => $availability['in_stock'],
                'available_quantity' => $availability['available_quantity'],
                'published_at' => $fresh->published_at,
                'payload' => $payload,
            ],
        );
    }

    public function deleteProduct(Product|int $product): void
    {
        $productId = $product instanceof Product ? $product->id : $product;

        CatalogProductProjection::query()
            ->where('product_id', $productId)
            ->delete();
    }

    public function syncAttribute(ProductAttribute $attribute): void
    {
        $this->syncProduct($attribute->product_id);
    }

    public function syncCategoryProducts(Category $category): void
    {
        $categoryIds = collect([$category->id]);
        $parentIds = $categoryIds;

        while ($parentIds->isNotEmpty()) {
            $parentIds = Category::query()
                ->whereIn('parent_id', $parentIds)
                ->pluck('id');
            $categoryIds = $categoryIds->merge($parentIds);
        }

        Product::query()
            ->whereIn('category_id', $categoryIds)
            ->orderBy('id')
            ->chunkById(100, function (Collection $products): void {
                foreach ($products as $product) {
                    $this->syncProduct($product->id);
                    app(CatalogSearchIndexService::class)->requestProduct($product->id);
                }
            });
    }

    public function syncAvailability(int $productId): void
    {
        $availability = $this->inventory->availabilityForProductIds([$productId])[$productId] ?? $this->emptyAvailability();

        /** @var CatalogProductProjection|null $projection */
        $projection = CatalogProductProjection::query()
            ->where('product_id', $productId)
            ->first();

        if (! $projection instanceof CatalogProductProjection) {
            $this->syncProduct($productId);

            return;
        }

        $payload = $projection->payload;
        $payload['availability'] = $availability;

        $projection->forceFill([
            'in_stock' => $availability['in_stock'],
            'available_quantity' => $availability['available_quantity'],
            'payload' => $payload,
        ])->save();
    }

    /**
     * @param  array{in_stock: bool, available_quantity: int}  $availability
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, array $availability): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'image_url' => $product->imageFile?->url(),
            'rating' => (float) $product->rating,
            'rating_count' => $product->rating_count,
            'status' => $product->status,
            'availability' => $availability,
            'published_at' => $product->published_at?->toJSON(),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
                'path' => $this->urls->categoryPath($product->category),
                'url' => $this->urls->categoryUrl($product->category),
                'breadcrumbs' => $this->urls->breadcrumbs($product->category),
                'image_url' => $product->category->imageFile?->url(),
            ] : null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
                'slug' => $product->brand->slug,
                'logo_url' => $product->brand->logoFile?->url(),
            ] : null,
            'attributes' => $product->attributes
                ->sortBy('name')
                ->map(fn (ProductAttribute $attribute): array => [
                    'name' => $attribute->name,
                    'value' => $attribute->value,
                ])
                ->values()
                ->all(),
            'offers' => $product->offers
                ->sortBy('sku')
                ->map(fn (ProductOffer $offer): array => [
                    'id' => $offer->id,
                    'name' => $offer->name,
                    'sku' => $offer->sku,
                    'status' => $offer->status,
                    'image_url' => $offer->imageFile?->url(),
                    'attributes' => $offer->attributes ?? [],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{in_stock: bool, available_quantity: int}
     */
    private function emptyAvailability(): array
    {
        return [
            'in_stock' => false,
            'available_quantity' => 0,
        ];
    }
}
