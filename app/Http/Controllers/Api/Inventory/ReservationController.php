<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Services\IdempotencyConflict;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Observability\MetricsCollector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReservationController extends Controller
{
    public function store(Request $request, InventoryService $inventory, MetricsCollector $metrics): JsonResponse
    {
        $payload = $request->validate([
            'stock_item_id' => ['required_without_all:product_id,sku', 'integer', 'min:1', 'exists:inventory_stock_items,id'],
            'product_id' => ['required_without_all:stock_item_id,sku', 'integer', 'min:1', 'exists:catalog_products,id'],
            'sku' => ['required_without_all:stock_item_id,product_id', 'string', 'max:255'],
            'city_code' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reservation_expires_at' => ['required', 'date', 'after:now'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key', $payload['idempotency_key'] ?? null);

        if ($idempotencyKey === null) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $stockItem = $this->resolveStockItem($payload);

        if ($stockItem === null) {
            return response()->json(['message' => 'Stock not found'], 404);
        }

        try {
            $reservation = $inventory->reserve(
                $stockItem,
                (int) $payload['quantity'],
                $idempotencyKey,
                Carbon::parse($payload['reservation_expires_at']),
                'api',
                $idempotencyKey,
            );
        } catch (IdempotencyConflict|InsufficientStock $exception) {
            $metrics->increment('stockflow_inventory_reservation_conflicts_total', [
                'reason' => $exception instanceof IdempotencyConflict ? 'idempotency' : 'insufficient_stock',
            ]);

            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->reservationPayload($reservation)], 201);
    }

    public function cancel(Request $request, InventoryService $inventory): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255', 'exists:inventory_reservations,idempotency_key'],
        ]);

        $reservation = $inventory->cancelReservation($payload['idempotency_key']);

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationPayload(Reservation $reservation): array
    {
        $reservation->loadMissing('stockItem.warehouse');

        return [
            'id' => $reservation->id,
            'stock_item_id' => $reservation->stock_item_id,
            'warehouse_id' => $reservation->stockItem?->warehouse_id,
            'warehouse_code' => $reservation->stockItem?->warehouse?->code,
            'city_code' => $reservation->stockItem?->warehouse?->city_code,
            'idempotency_key' => $reservation->idempotency_key,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status,
            'reservation_expires_at' => $reservation->reservation_expires_at?->toJSON(),
            'canceled_at' => $reservation->canceled_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveStockItem(array $payload): ?StockItem
    {
        if (isset($payload['stock_item_id'])) {
            return StockItem::query()->findOrFail($payload['stock_item_id']);
        }

        /** @var StockItem|null $stockItem */
        $stockItem = StockItem::query()
            ->select('inventory_stock_items.*')
            ->join('inventory_warehouses', 'inventory_warehouses.id', '=', 'inventory_stock_items.warehouse_id')
            ->when(isset($payload['product_id']), fn (Builder $query): Builder => $query->where('product_id', (int) $payload['product_id']))
            ->when(isset($payload['sku']), fn (Builder $query): Builder => $query->where('sku', $payload['sku']))
            ->when(isset($payload['city_code']), fn (Builder $query): Builder => $query->where('inventory_warehouses.city_code', $payload['city_code']))
            ->where('inventory_warehouses.is_active', true)
            ->whereRaw('on_hand_quantity - reserved_quantity >= ?', [(int) $payload['quantity']])
            ->orderByRaw('on_hand_quantity - reserved_quantity DESC')
            ->orderBy('inventory_stock_items.id')
            ->first();

        return $stockItem;
    }
}
