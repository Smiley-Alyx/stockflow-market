<?php

namespace App\Domains\Pricing\Read;

use App\Domains\Pricing\Models\ProductPrice;

class PricingReadService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function pricesForProductIds(array $productIds): array
    {
        $productIds = collect($productIds)
            ->map(fn (mixed $productId): int => (int) $productId)
            ->filter(fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        return ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->orderBy('product_id')
            ->get()
            ->map(fn (ProductPrice $price): array => $this->pricePayload($price))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pricePayload(ProductPrice $price): array
    {
        return [
            'product_id' => $price->product_id,
            'amount_minor' => $price->amount_minor,
            'currency' => $price->currency,
        ];
    }
}
