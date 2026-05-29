<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderConflict;
use App\Domains\Orders\Services\OrderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderConfirmationController extends Controller
{
    public function store(int $id, OrderService $orders): JsonResponse
    {
        try {
            $order = $orders->confirm($id);
        } catch (OrderConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->orderPayload($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'cart_id' => $order->cart_id,
            'status' => $order->status,
            'total_amount_minor' => $order->total_amount_minor,
            'currency' => $order->currency,
            'confirmed_at' => $order->confirmed_at?->toJSON(),
            'items' => $order->items
                ->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'sku' => $item->sku,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_amount_minor' => $item->unit_amount_minor,
                    'currency' => $item->currency,
                    'line_amount_minor' => $item->line_amount_minor,
                ])
                ->values()
                ->all(),
        ];
    }
}
