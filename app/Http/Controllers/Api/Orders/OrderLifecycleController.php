<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderConflict;
use App\Domains\Orders\Services\OrderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OrderLifecycleController extends Controller
{
    public function paid(int $id, OrderService $orders): JsonResponse
    {
        try {
            $order = $orders->pay($id);
        } catch (OrderConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->orderPayload($order)]);
    }

    public function cancelled(int $id, OrderService $orders): JsonResponse
    {
        try {
            $order = $orders->cancel($id);
        } catch (OrderConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->orderPayload($order)]);
    }

    public function expired(int $id, OrderService $orders): JsonResponse
    {
        try {
            $order = $orders->expire($id);
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
            'city_code' => $order->city_code,
            'promo_code' => $order->promo_code,
            'subtotal_amount_minor' => $order->subtotal_amount_minor,
            'discount_amount_minor' => $order->discount_amount_minor,
            'total_amount_minor' => $order->total_amount_minor,
            'currency' => $order->currency,
            'confirmed_at' => $order->confirmed_at?->toJSON(),
            'paid_at' => $order->paid_at?->toJSON(),
            'cancelled_at' => $order->cancelled_at?->toJSON(),
            'expired_at' => $order->expired_at?->toJSON(),
            'items' => $order->items
                ->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'sku' => $item->sku,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'pricing_product_price_id' => $item->pricing_product_price_id,
                    'price_type' => $item->price_type,
                    'price_city_code' => $item->price_city_code,
                    'price_version' => $item->price_version,
                    'price_active_from' => $item->price_active_from?->toJSON(),
                    'unit_amount_minor' => $item->unit_amount_minor,
                    'currency' => $item->currency,
                    'line_amount_minor' => $item->line_amount_minor,
                ])
                ->values()
                ->all(),
        ];
    }
}
