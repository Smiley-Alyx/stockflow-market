<?php

namespace App\Domains\Inventory\Read;

use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class InventoryReadService
{
    /**
     * @return array<string, mixed>|null
     */
    public function stock(?string $sku = null, ?int $productId = null, ?string $cityCode = null): ?array
    {
        $items = StockItem::query()
            ->with('warehouse')
            ->when($sku !== null, fn (Builder $query): Builder => $query->where('sku', $sku))
            ->when($productId !== null, fn (Builder $query): Builder => $query->where('product_id', $productId))
            ->when($cityCode !== null, fn (Builder $query): Builder => $query->whereHas(
                'warehouse',
                fn (Builder $query): Builder => $query->where('city_code', $cityCode),
            ))
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
                    'warehouse_name' => $item->warehouse?->name,
                    'city_code' => $item->warehouse?->city_code,
                    'city_name' => $item->warehouse?->city_name,
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
    public function availabilityForProductIds(array $productIds, ?string $cityCode = null): array
    {
        $productIds = collect($productIds)
            ->map(fn (mixed $productId): int => (int) $productId)
            ->filter(fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $cityCode = trim((string) $cityCode);
        $cityCode = $cityCode === '' ? null : strtolower($cityCode);

        return StockItem::query()
            ->select('product_id')
            ->selectRaw('SUM(CASE WHEN on_hand_quantity > reserved_quantity THEN on_hand_quantity - reserved_quantity ELSE 0 END) as available_quantity')
            ->whereIn('product_id', $productIds)
            ->whereHas('warehouse', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->when($cityCode !== null, fn (Builder $query): Builder => $query->where('city_code', $cityCode))
            )
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function warehouseAvailabilityForProductId(int $productId): array
    {
        return StockItem::query()
            ->with('warehouse')
            ->where('product_id', $productId)
            ->whereHas('warehouse', fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (StockItem $item): array => [
                'warehouse_id' => $item->warehouse_id,
                'warehouse_code' => $item->warehouse?->code,
                'warehouse_name' => $item->warehouse?->name,
                'city_code' => $item->warehouse?->city_code,
                'city_name' => $item->warehouse?->city_name,
                'available_quantity' => $item->availableQuantity(),
                'in_stock' => $item->availableQuantity() > 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, string>>
     */
    public function availableCityCodesForProductIds(array $productIds): array
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
            ->select('product_id', 'inventory_warehouses.city_code')
            ->join('inventory_warehouses', 'inventory_warehouses.id', '=', 'inventory_stock_items.warehouse_id')
            ->where('inventory_warehouses.is_active', true)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id', 'inventory_warehouses.city_code')
            ->havingRaw('SUM(CASE WHEN on_hand_quantity > reserved_quantity THEN on_hand_quantity - reserved_quantity ELSE 0 END) > 0')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($items): array => $items
                ->pluck('city_code')
                ->map(fn (mixed $cityCode): string => (string) $cityCode)
                ->unique()
                ->sort()
                ->values()
                ->all()
            )
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: string|null, per_page: int}}
     */
    public function movements(?int $stockItemId = null, ?string $type = null, ?string $cursor = null, int $perPage = 50): array
    {
        $perPage = max(1, min($perPage, 100));
        $decodedCursor = $cursor === null ? null : $this->decodeCursor($cursor);

        $movements = StockMovement::query()
            ->when($stockItemId !== null, fn (Builder $query): Builder => $query->where('stock_item_id', $stockItemId))
            ->when($type !== null, fn (Builder $query): Builder => $query->where('type', $type))
            ->when($decodedCursor !== null, function (Builder $query) use ($decodedCursor): Builder {
                return $query->where(function (Builder $query) use ($decodedCursor): void {
                    $query
                        ->where('occurred_at', '<', $decodedCursor['occurred_at'])
                        ->orWhere(function (Builder $query) use ($decodedCursor): void {
                            $query
                                ->where('occurred_at', $decodedCursor['occurred_at'])
                                ->where('id', '<', $decodedCursor['id']);
                        });
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $movements->count() > $perPage;
        $page = $movements->take($perPage)->values();
        $last = $page->last();

        return [
            'data' => $page
                ->map(fn (StockMovement $movement): array => [
                    'id' => $movement->id,
                    'stock_item_id' => $movement->stock_item_id,
                    'reservation_id' => $movement->reservation_id,
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'metadata' => $movement->metadata,
                    'occurred_at' => $movement->occurred_at?->toJSON(),
                ])
                ->all(),
            'meta' => [
                'next_cursor' => $hasMore && $last instanceof StockMovement ? $this->encodeCursor($last) : null,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * @return array{occurred_at: CarbonImmutable, id: int}
     */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid movement cursor.');
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['occurred_at'], $payload['id'])) {
            throw new InvalidArgumentException('Invalid movement cursor.');
        }

        return [
            'occurred_at' => CarbonImmutable::parse((string) $payload['occurred_at']),
            'id' => (int) $payload['id'],
        ];
    }

    private function encodeCursor(StockMovement $movement): string
    {
        return base64_encode(json_encode([
            'occurred_at' => $movement->occurred_at instanceof Carbon ? $movement->occurred_at->toJSON() : (string) $movement->occurred_at,
            'id' => $movement->id,
        ], JSON_THROW_ON_ERROR));
    }
}
