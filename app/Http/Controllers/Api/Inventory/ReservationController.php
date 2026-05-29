<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Services\IdempotencyConflict;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReservationController extends Controller
{
    public function store(Request $request, InventoryService $inventory): JsonResponse
    {
        $payload = $request->validate([
            'stock_item_id' => ['required', 'integer', 'min:1', 'exists:inventory_stock_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reservation_expires_at' => ['required', 'date', 'after:now'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key', $payload['idempotency_key'] ?? null);

        if ($idempotencyKey === null) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        /** @var StockItem $stockItem */
        $stockItem = StockItem::query()->findOrFail($payload['stock_item_id']);

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
        return [
            'id' => $reservation->id,
            'stock_item_id' => $reservation->stock_item_id,
            'idempotency_key' => $reservation->idempotency_key,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status,
            'reservation_expires_at' => $reservation->reservation_expires_at?->toJSON(),
            'canceled_at' => $reservation->canceled_at?->toJSON(),
        ];
    }
}
