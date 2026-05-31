<?php

namespace App\Domains\Homepage\Read;

use App\Domains\Catalog\Models\CatalogProductProjection;
use App\Domains\Homepage\Models\HomepageBlock;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;

class HomepageReadService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = HomepageBlock::query()
            ->where('is_active', true)
            ->with(['imageFile', 'products'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $products = $this->publishedProductProjections($blocks);
        $cities = null;

        return $blocks
            ->map(function (HomepageBlock $block) use ($products, &$cities): array {
                return [
                    'id' => $block->id,
                    'type' => $block->type,
                    'title' => $block->title,
                    'position' => $block->position,
                    'content' => match (true) {
                        $block->isProductBlock() => [
                            'products' => $block->products
                                ->map(fn ($product) => $products->get($product->id)?->payload)
                                ->filter()
                                ->values()
                                ->all(),
                        ],
                        $block->type === HomepageBlock::TYPE_CITIES => [
                            'cities' => $cities ??= $this->cities(),
                        ],
                        default => array_merge(
                            $block->settings ?? [],
                            ['image_url' => $block->imageFile?->url()],
                        ),
                    },
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, HomepageBlock>  $blocks
     * @return Collection<int, CatalogProductProjection>
     */
    private function publishedProductProjections(Collection $blocks): Collection
    {
        $productIds = $blocks
            ->flatMap(fn (HomepageBlock $block) => $block->products->pluck('id'))
            ->unique()
            ->values();

        return CatalogProductProjection::query()
            ->whereIn('product_id', $productIds)
            ->where('status', 'published')
            ->where('category_is_active', true)
            ->get()
            ->keyBy('product_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cities(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('city_name')
            ->orderBy('name')
            ->get()
            ->groupBy('city_code')
            ->map(function (Collection $warehouses, string $cityCode): array {
                /** @var Warehouse $first */
                $first = $warehouses->first();

                return [
                    'code' => $cityCode,
                    'name' => $first->city_name,
                    'latitude' => (float) $first->latitude,
                    'longitude' => (float) $first->longitude,
                    'warehouses' => $warehouses
                        ->map(fn (Warehouse $warehouse): array => [
                            'id' => $warehouse->id,
                            'code' => $warehouse->code,
                            'name' => $warehouse->name,
                            'latitude' => (float) $warehouse->latitude,
                            'longitude' => (float) $warehouse->longitude,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
