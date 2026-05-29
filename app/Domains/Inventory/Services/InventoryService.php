<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function receive(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RECEIVED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function reserve(
        StockItem $stockItem,
        int $quantity,
        string $idempotencyKey,
        CarbonInterface $reservationExpiresAt,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?array $metadata = null,
    ): Reservation {
        $this->assertQuantity($quantity);
        $reservationExpiresAt = $reservationExpiresAt->toImmutable()->startOfSecond();

        if ($reservationExpiresAt->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Reservation expiration must be in the future.');
        }

        return DB::transaction(function () use ($stockItem, $quantity, $idempotencyKey, $reservationExpiresAt, $referenceType, $referenceId, $metadata): Reservation {
            /** @var Reservation|null $existing */
            $existing = Reservation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->expireReservationIfNeeded($existing);
                $existing->refresh();

                if (
                    $existing->stock_item_id !== $stockItem->id
                    || $existing->quantity !== $quantity
                    || ! $existing->reservation_expires_at->equalTo($reservationExpiresAt)
                ) {
                    throw IdempotencyConflict::reservation($idempotencyKey);
                }

                return $existing;
            }

            $this->incrementReservedQuantity($stockItem, $quantity);

            /** @var Reservation $reservation */
            $reservation = Reservation::query()->create([
                'stock_item_id' => $stockItem->id,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'status' => Reservation::STATUS_ACTIVE,
                'reservation_expires_at' => $reservationExpiresAt,
                'metadata' => $metadata,
            ]);

            $this->createMovement($reservation, StockMovement::TYPE_RESERVED, $quantity, $referenceType, $referenceId, $metadata);

            return $reservation;
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function release(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RELEASED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function deduct(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_DEDUCTED, $quantity, $referenceType, $referenceId, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function returnStock(StockItem $stockItem, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        return $this->change($stockItem, StockMovement::TYPE_RETURNED, $quantity, $referenceType, $referenceId, $metadata);
    }

    public function cancelReservation(string $idempotencyKey): Reservation
    {
        return DB::transaction(function () use ($idempotencyKey): Reservation {
            /** @var Reservation $reservation */
            $reservation = Reservation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            $this->expireReservationIfNeeded($reservation);
            $reservation->refresh();

            if (! $reservation->isActive()) {
                return $reservation;
            }

            $this->decrementReservedQuantity($reservation);

            $reservation->status = Reservation::STATUS_CANCELED;
            $reservation->canceled_at = now();
            $reservation->save();

            $this->createMovement($reservation, StockMovement::TYPE_RELEASED, $reservation->quantity);

            return $reservation;
        });
    }

    public function expireReservations(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $expired = 0;

        Reservation::query()
            ->where('status', Reservation::STATUS_ACTIVE)
            ->where('reservation_expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function (Collection $reservations) use (&$expired, $now): void {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, &$expired, $now): void {
                        /** @var Reservation $locked */
                        $locked = Reservation::query()
                            ->whereKey($reservation->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (! $locked->isActive() || $locked->reservation_expires_at->greaterThan($now)) {
                            return;
                        }

                        $this->decrementReservedQuantity($locked);
                        $locked->status = Reservation::STATUS_EXPIRED;
                        $locked->save();

                        $this->createMovement($locked, StockMovement::TYPE_EXPIRED, $locked->quantity);
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function change(StockItem $stockItem, string $type, int $quantity, ?string $referenceType, ?string $referenceId, ?array $metadata): StockMovement
    {
        $this->assertQuantity($quantity);

        return DB::transaction(function () use ($stockItem, $type, $quantity, $referenceType, $referenceId, $metadata): StockMovement {
            /** @var StockItem $locked */
            $locked = StockItem::query()
                ->whereKey($stockItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->applyQuantityChange($locked, $type, $quantity);
            $locked->save();

            $movement = $locked->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);

            /** @var StockItem $changed */
            $changed = $locked->fresh();

            StockChanged::dispatch($changed, $movement);

            return $movement;
        });
    }

    private function applyQuantityChange(StockItem $stockItem, string $type, int $quantity): void
    {
        match ($type) {
            StockMovement::TYPE_RECEIVED => $stockItem->on_hand_quantity += $quantity,
            StockMovement::TYPE_RETURNED => $stockItem->on_hand_quantity += $quantity,
            StockMovement::TYPE_RELEASED => $this->releaseQuantity($stockItem, $quantity),
            StockMovement::TYPE_DEDUCTED => $this->deductQuantity($stockItem, $quantity),
            default => throw new InvalidArgumentException("Unsupported stock movement type {$type}."),
        };
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Stock movement quantity must be greater than zero.');
        }
    }

    private function releaseQuantity(StockItem $stockItem, int $quantity): void
    {
        if ($stockItem->reserved_quantity < $quantity) {
            throw InsufficientStock::reserved($stockItem->sku, $quantity, $stockItem->reserved_quantity);
        }

        $stockItem->reserved_quantity -= $quantity;
    }

    private function deductQuantity(StockItem $stockItem, int $quantity): void
    {
        if ($stockItem->reserved_quantity < $quantity) {
            throw InsufficientStock::reserved($stockItem->sku, $quantity, $stockItem->reserved_quantity);
        }

        $stockItem->reserved_quantity -= $quantity;
        $stockItem->on_hand_quantity -= $quantity;
    }

    private function incrementReservedQuantity(StockItem $stockItem, int $quantity): void
    {
        $affected = StockItem::query()
            ->whereKey($stockItem->id)
            ->whereRaw('on_hand_quantity - reserved_quantity >= ?', [$quantity])
            ->update([
                'reserved_quantity' => DB::raw('reserved_quantity + '.$quantity),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            /** @var StockItem $fresh */
            $fresh = StockItem::query()->findOrFail($stockItem->id);

            throw InsufficientStock::available($fresh->sku, $quantity, $fresh->availableQuantity());
        }
    }

    private function decrementReservedQuantity(Reservation $reservation): void
    {
        $affected = StockItem::query()
            ->whereKey($reservation->stock_item_id)
            ->where('reserved_quantity', '>=', $reservation->quantity)
            ->update([
                'reserved_quantity' => DB::raw('reserved_quantity - '.$reservation->quantity),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            /** @var StockItem $stockItem */
            $stockItem = StockItem::query()->findOrFail($reservation->stock_item_id);

            throw InsufficientStock::reserved($stockItem->sku, $reservation->quantity, $stockItem->reserved_quantity);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function createMovement(Reservation $reservation, string $type, int $quantity, ?string $referenceType = null, ?string $referenceId = null, ?array $metadata = null): StockMovement
    {
        $movement = StockMovement::query()->create([
            'stock_item_id' => $reservation->stock_item_id,
            'reservation_id' => $reservation->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        /** @var StockItem $changed */
        $changed = StockItem::query()->findOrFail($reservation->stock_item_id);

        StockChanged::dispatch($changed, $movement);

        return $movement;
    }

    private function expireReservationIfNeeded(Reservation $reservation): void
    {
        if (! $reservation->isActive() || $reservation->reservation_expires_at->greaterThan(now())) {
            return;
        }

        $this->decrementReservedQuantity($reservation);
        $reservation->status = Reservation::STATUS_EXPIRED;
        $reservation->save();

        $this->createMovement($reservation, StockMovement::TYPE_EXPIRED, $reservation->quantity);
    }
}
