<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Catalog\Models\CatalogProductProjection;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Inventory\Read\InventoryReadService;
use Illuminate\Support\Collection;

class CatalogProjectionService
{
    public function __construct(
        private readonly InventoryReadService $inventory,
    ) {}

    public function syncProduct(Product|int $product): void
    {
        $productId = $product instanceof Product ? $product->id : $product;

        /** @var Product|null $fresh */
        $fresh = Product::query()
            ->with(['category', 'attributes'])
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
        Product::query()
            ->where('category_id', $category->id)
            ->orderBy('id')
            ->chunkById(100, function (Collection $products): void {
                foreach ($products as $product) {
                    $this->syncProduct($product->id);
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
            'status' => $product->status,
            'availability' => $availability,
            'published_at' => $product->published_at?->toJSON(),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'attributes' => $product->attributes
                ->sortBy('name')
                ->map(fn (ProductAttribute $attribute): array => [
                    'name' => $attribute->name,
                    'value' => $attribute->value,
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
