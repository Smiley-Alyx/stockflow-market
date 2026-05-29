<?php

namespace App\Domains\Pricing\Read;

use App\Domains\Pricing\Models\ProductPrice;

class PricingReadService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function pricesForProductIds(array $productIds, array $priceTypes = []): array
    {
        $productIds = collect($productIds)
            ->map(fn (mixed $productId): int => (int) $productId)
            ->filter(fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        $priceTypes = collect($priceTypes)
            ->map(fn (mixed $priceType): string => (string) $priceType)
            ->filter(fn (string $priceType): bool => $priceType !== '')
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        return ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->when($priceTypes->isNotEmpty(), fn ($query) => $query->whereIn('price_type', $priceTypes))
            ->where('is_active', true)
            ->orderBy('product_id')
            ->orderBy('price_type')
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
            'price_type' => $price->price_type,
            'amount_minor' => $price->amount_minor,
            'currency' => $price->currency,
        ];
    }
}
