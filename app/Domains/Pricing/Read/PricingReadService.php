<?php

namespace App\Domains\Pricing\Read;

use App\Domains\Pricing\Models\ProductPrice;
use Illuminate\Database\Eloquent\Builder;

class PricingReadService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function pricesForProductIds(array $productIds, array $priceTypes = [], ?string $cityCode = null): array
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
        $cityCode = trim((string) $cityCode);
        $cityCode = $cityCode === '' ? null : strtolower($cityCode);

        if ($productIds->isEmpty()) {
            return [];
        }

        $prices = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->when($priceTypes->isNotEmpty(), fn ($query) => $query->whereIn('price_type', $priceTypes))
            ->when(
                $cityCode !== null,
                fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                    ->whereNull('city_code')
                    ->orWhere('city_code', $cityCode)
                ),
                fn (Builder $query): Builder => $query->whereNull('city_code'),
            )
            ->where($this->activePriceWindow(...))
            ->orderBy('product_id')
            ->orderBy('price_type')
            ->orderByRaw('case when city_code is null then 1 else 0 end')
            ->orderByDesc('active_from')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (ProductPrice $price): string => $price->product_id.'|'.$price->price_type)
            ->map(fn (ProductPrice $price): array => $this->pricePayload($price))
            ->values()
            ->all();

        return $prices;
    }

    /**
     * @return array<string, mixed>
     */
    private function pricePayload(ProductPrice $price): array
    {
        return [
            'product_id' => $price->product_id,
            'price_type' => $price->price_type,
            'city_code' => $price->city_code,
            'price_version' => $price->price_version,
            'amount_minor' => $price->amount_minor,
            'currency' => $price->currency,
            'active_from' => $price->active_from?->toJSON(),
        ];
    }

    private function activePriceWindow(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('active_from')
                ->orWhere('active_from', '<=', $now)
            )
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('active_until')
                ->orWhere('active_until', '>', $now)
            );
    }
}
