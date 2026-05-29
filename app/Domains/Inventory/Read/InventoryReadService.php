<?php

namespace App\Domains\Inventory\Read;

use App\Domains\Inventory\Models\StockItem;
use Illuminate\Database\Eloquent\Builder;

class InventoryReadService
{
    /**
     * @return array<string, mixed>|null
     */
    public function stock(?string $sku = null, ?int $productId = null): ?array
    {
        $items = StockItem::query()
            ->with('warehouse')
            ->when($sku !== null, fn (Builder $query): Builder => $query->where('sku', $sku))
            ->when($productId !== null, fn (Builder $query): Builder => $query->where('product_id', $productId))
            ->orderBy('warehouse_id')
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        return [
            'sku' => $items->first()->sku,
            'product_id' => $items->first()->product_id,
            'on_hand_quantity' => $items->sum('on_hand_quantity'),
            'reserved_quantity' => $items->sum('reserved_quantity'),
            'available_quantity' => $items->sum(fn (StockItem $item): int => $item->availableQuantity()),
            'warehouses' => $items
                ->map(fn (StockItem $item): array => [
                    'warehouse_id' => $item->warehouse_id,
                    'warehouse_code' => $item->warehouse?->code,
                    'on_hand_quantity' => $item->on_hand_quantity,
                    'reserved_quantity' => $item->reserved_quantity,
                    'available_quantity' => $item->availableQuantity(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{in_stock: bool, available_quantity: int}>
     */
    public function availabilityForProductIds(array $productIds): array
    {
        $productIds = collect($productIds)
            ->map(fn (mixed $productId): int => (int) $productId)
            ->filter(fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        return StockItem::query()
            ->select('product_id')
            ->selectRaw('SUM(CASE WHEN on_hand_quantity > reserved_quantity THEN on_hand_quantity - reserved_quantity ELSE 0 END) as available_quantity')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(function (StockItem $item): array {
                $availableQuantity = (int) $item->available_quantity;

                return [
                    $item->product_id => [
                        'in_stock' => $availableQuantity > 0,
                        'available_quantity' => $availableQuantity,
                    ],
                ];
            })
            ->all();
    }
}
