<?php

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Inventory\Read\InventoryReadService;
use App\Domains\Pricing\Read\PricingReadService;

class CatalogSearchDocumentFactory
{
    public function __construct(
        private readonly InventoryReadService $inventory,
        private readonly PricingReadService $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(Product $product): array
    {
        $product = Product::query()
            ->with(['brand', 'category', 'attributes', 'offers'])
            ->find($product->id) ?? $product->loadMissing(['brand', 'category', 'attributes', 'offers']);

        $availability = $this->inventory->availabilityForProductIds([$product->id])[$product->id] ?? $this->emptyAvailability();
        $availability['city_codes'] = $this->inventory->availableCityCodesForProductIds([$product->id])[$product->id] ?? [];

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
                'slug' => $product->brand->slug,
            ] : null,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'image_url' => $product->image_url,
            'rating' => (float) $product->rating,
            'rating_count' => $product->rating_count,
            'status' => $product->status,
            'availability' => $availability,
            'price' => $this->pricing->catalogPricesForProductIds([$product->id])[$product->id] ?? null,
            'published_at' => $product->published_at?->toJSON(),
            'attributes' => $product->attributes
                ->sortBy('name')
                ->map(fn (ProductAttribute $attribute): array => [
                    'name' => $attribute->name,
                    'value' => $attribute->value,
                ])
                ->values()
                ->all(),
            'filters' => $product->attributes
                ->groupBy('name')
                ->map(fn ($attributes): array => $attributes
                    ->pluck('value')
                    ->unique()
                    ->values()
                    ->all()
                )
                ->all(),
            'offers' => $product->offers
                ->sortBy('sku')
                ->map(fn (ProductOffer $offer): array => [
                    'id' => $offer->id,
                    'name' => $offer->name,
                    'sku' => $offer->sku,
                    'status' => $offer->status,
                    'image_url' => $offer->image_url,
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
