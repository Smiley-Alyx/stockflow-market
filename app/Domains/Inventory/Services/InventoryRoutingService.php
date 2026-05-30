<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryRoutingService
{
    public const STRATEGY_NEAREST = 'nearest_warehouse';

    public const STRATEGY_FALLBACK = 'fallback';

    public const STRATEGY_SPLIT = 'split_shipment';

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>|null  $metadata
     * @return Collection<int, Reservation>
     */
    public function reserve(
        array $criteria,
        int $quantity,
        string $idempotencyKey,
        CarbonInterface $reservationExpiresAt,
        string $strategy = self::STRATEGY_NEAREST,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?array $metadata = null,
    ): Collection {
        if (! in_array($strategy, [self::STRATEGY_NEAREST, self::STRATEGY_FALLBACK, self::STRATEGY_SPLIT], true)) {
            throw new InvalidArgumentException("Unsupported inventory routing strategy {$strategy}.");
        }

        if (isset($criteria['stock_item_id'])) {
            /** @var StockItem $stockItem */
            $stockItem = StockItem::query()->findOrFail((int) $criteria['stock_item_id']);

            return collect([
                $this->inventory->reserve(
                    $stockItem,
                    $quantity,
                    $idempotencyKey,
                    $reservationExpiresAt,
                    $referenceType,
                    $referenceId,
                    $metadata,
                ),
            ]);
        }

        return DB::transaction(function () use ($criteria, $quantity, $idempotencyKey, $reservationExpiresAt, $strategy, $referenceType, $referenceId, $metadata): Collection {
            if ($strategy === self::STRATEGY_SPLIT) {
                $existing = $this->existingSplitReservations($idempotencyKey);

                if ($existing->isNotEmpty()) {
                    $this->assertExistingSplitMatches($existing, $quantity, $reservationExpiresAt, $idempotencyKey);

                    return $existing;
                }
            }

            $candidates = $this->candidateStockItems($criteria, $strategy);

            if ($candidates->isEmpty()) {
                return collect();
            }

            if ($strategy !== self::STRATEGY_SPLIT) {
                $stockItem = $this->selectSingleCandidate($candidates, $quantity);

                if ($stockItem === null) {
                    throw InsufficientStock::available((string) ($criteria['sku'] ?? 'product '.$criteria['product_id']), $quantity, $candidates->sum->availableQuantity());
                }

                return collect([
                    $this->inventory->reserve(
                        $stockItem,
                        $quantity,
                        $idempotencyKey,
                        $reservationExpiresAt,
                        $referenceType,
                        $referenceId,
                        $metadata,
                    ),
                ]);
            }

            return $this->reserveSplitShipment(
                $candidates,
                $quantity,
                $idempotencyKey,
                $reservationExpiresAt,
                $referenceType,
                $referenceId,
                $metadata,
                (string) ($criteria['sku'] ?? 'product '.$criteria['product_id']),
            );
        });
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function existingSplitReservations(string $idempotencyKey): Collection
    {
        /** @var Collection<int, Reservation> $reservations */
        $reservations = Reservation::query()
            ->where('metadata->route_parent_key', $idempotencyKey)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $reservations;
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     */
    private function assertExistingSplitMatches(Collection $reservations, int $quantity, CarbonInterface $reservationExpiresAt, string $idempotencyKey): void
    {
        /** @var Reservation $first */
        $first = $reservations->first();

        if (
            $reservations->sum('quantity') !== $quantity
            || ! $first->reservation_expires_at->equalTo($reservationExpiresAt->toImmutable()->startOfSecond())
        ) {
            throw IdempotencyConflict::reservation($idempotencyKey);
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, StockItem>
     */
    private function candidateStockItems(array $criteria, string $strategy): Collection
    {
        /** @var Collection<int, StockItem> $items */
        $items = StockItem::query()
            ->select('inventory_stock_items.*')
            ->with('warehouse')
            ->join('inventory_warehouses', 'inventory_warehouses.id', '=', 'inventory_stock_items.warehouse_id')
            ->when(isset($criteria['warehouse_id']), fn (Builder $query): Builder => $query->where('warehouse_id', (int) $criteria['warehouse_id']))
            ->when(isset($criteria['product_id']), fn (Builder $query): Builder => $query->where('product_id', (int) $criteria['product_id']))
            ->when(isset($criteria['sku']), fn (Builder $query): Builder => $query->where('sku', $criteria['sku']))
            ->where('inventory_warehouses.is_active', true)
            ->orderByRaw($this->cityPreferenceOrder($criteria))
            ->orderByRaw($strategy === self::STRATEGY_FALLBACK ? 'on_hand_quantity - reserved_quantity DESC' : 'inventory_stock_items.id ASC')
            ->orderBy('inventory_stock_items.id')
            ->lockForUpdate()
            ->get();

        return $items;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function cityPreferenceOrder(array $criteria): string
    {
        if (! isset($criteria['city_code'])) {
            return '0';
        }

        return "CASE WHEN inventory_warehouses.city_code = ".DB::getPdo()->quote((string) $criteria['city_code']).' THEN 0 ELSE 1 END';
    }

    /**
     * @param  Collection<int, StockItem>  $candidates
     */
    private function selectSingleCandidate(Collection $candidates, int $quantity): ?StockItem
    {
        return $candidates->first(fn (StockItem $stockItem): bool => $stockItem->availableQuantity() >= $quantity);
    }

    /**
     * @param  Collection<int, StockItem>  $candidates
     * @param  array<string, mixed>|null  $metadata
     * @return Collection<int, Reservation>
     */
    private function reserveSplitShipment(
        Collection $candidates,
        int $quantity,
        string $idempotencyKey,
        CarbonInterface $reservationExpiresAt,
        ?string $referenceType,
        ?string $referenceId,
        ?array $metadata,
        string $sku,
    ): Collection {
        $available = $candidates->sum->availableQuantity();

        if ($available < $quantity) {
            throw InsufficientStock::available($sku, $quantity, $available);
        }

        $remaining = $quantity;
        $reservations = collect();
        $shipment = 1;

        foreach ($candidates as $stockItem) {
            $reservedQuantity = min($remaining, $stockItem->availableQuantity());

            if ($reservedQuantity < 1) {
                continue;
            }

            $reservations->push($this->inventory->reserve(
                $stockItem,
                $reservedQuantity,
                "{$idempotencyKey}:shipment:{$shipment}",
                $reservationExpiresAt,
                $referenceType,
                $referenceId,
                array_merge($metadata ?? [], [
                    'route_parent_key' => $idempotencyKey,
                    'route_shipment' => $shipment,
                    'route_strategy' => self::STRATEGY_SPLIT,
                    'route_total_quantity' => $quantity,
                ]),
            ));

            $remaining -= $reservedQuantity;
            $shipment++;

            if ($remaining === 0) {
                return $reservations;
            }
        }

        throw InsufficientStock::available($sku, $quantity, $quantity - $remaining);
    }
}
