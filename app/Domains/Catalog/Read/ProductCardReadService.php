<?php

namespace App\Domains\Catalog\Read;

use App\Domains\Inventory\Read\InventoryReadService;
use App\Domains\Pricing\Read\PricingReadService;

class ProductCardReadService
{
    public function __construct(
        private readonly CatalogReadService $catalog,
        private readonly InventoryReadService $inventory,
        private readonly PricingReadService $pricing,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function productBySlug(string $slug): ?array
    {
        $product = $this->catalog->productBySlug($slug);

        if ($product === null) {
            return null;
        }

        $productId = (int) $product['id'];

        return array_merge($product, [
            'price' => $this->pricing->catalogPricesForProductIds([$productId])[$productId] ?? null,
            'warehouses' => $this->inventory->warehouseAvailabilityForProductId($productId),
        ]);
    }
}
