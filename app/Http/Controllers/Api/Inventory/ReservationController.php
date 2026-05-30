<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Services\IdempotencyConflict;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryRoutingService;
use App\Domains\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Observability\MetricsCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReservationController extends Controller
{
    public function store(Request $request, InventoryRoutingService $routing, MetricsCollector $metrics): JsonResponse
    {
        $payload = $request->validate([
            'stock_item_id' => ['required_without_all:product_id,sku', 'integer', 'min:1', 'exists:inventory_stock_items,id'],
            'warehouse_id' => ['sometimes', 'integer', 'min:1', 'exists:inventory_warehouses,id'],
            'product_id' => ['required_without_all:stock_item_id,sku', 'integer', 'min:1', 'exists:catalog_products,id'],
            'sku' => ['required_without_all:stock_item_id,product_id', 'string', 'max:255'],
            'city_code' => ['sometimes', 'string', 'max:255'],
            'routing_strategy' => ['sometimes', 'string', 'in:nearest_warehouse,fallback,split_shipment'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reservation_expires_at' => ['required', 'date', 'after:now'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key', $payload['idempotency_key'] ?? null);

        if ($idempotencyKey === null) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        try {
            $reservations = $routing->reserve(
                $payload,
                (int) $payload['quantity'],
                $idempotencyKey,
                Carbon::parse($payload['reservation_expires_at']),
                $payload['routing_strategy'] ?? InventoryRoutingService::STRATEGY_NEAREST,
                'api',
                $idempotencyKey,
            );
        } catch (IdempotencyConflict|InsufficientStock $exception) {
            $metrics->increment('stockflow_inventory_reservation_conflicts_total', [
                'reason' => $exception instanceof IdempotencyConflict ? 'idempotency' : 'insufficient_stock',
            ]);

            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($reservations->isEmpty()) {
            return response()->json(['message' => 'Stock not found'], 404);
        }

        return response()->json(['data' => $this->routePayload($reservations, $payload['routing_strategy'] ?? InventoryRoutingService::STRATEGY_NEAREST)], 201);
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
     * @param  Collection<int, Reservation>  $reservations
     * @return array<string, mixed>
     */
    private function routePayload(Collection $reservations, string $strategy): array
    {
        /** @var Reservation $primary */
        $primary = $reservations->first();
        $payload = $this->reservationPayload($primary);
        $payload['routing_strategy'] = $strategy;
        $payload['shipments'] = $reservations
            ->values()
            ->map(fn (Reservation $reservation): array => $this->reservationPayload($reservation))
            ->all();

        return $payload;
    }
}
