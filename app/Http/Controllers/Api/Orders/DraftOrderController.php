<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderConflict;
use App\Domains\Orders\Services\OrderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftOrderController extends Controller
{
    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $payload = $request->validate([
            'cart_id' => ['required', 'integer', 'min:1', 'exists:orders_carts,id'],
            'city_code' => ['sometimes', 'string', 'max:255'],
            'promo_code' => ['sometimes', 'string', 'max:255'],
            'product_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:catalog_products,id'],
        ]);

        try {
            $order = $orders->createDraft(
                (int) $payload['cart_id'],
                $payload['city_code'] ?? null,
                $payload['promo_code'] ?? null,
                $payload['product_ids'] ?? null,
            );
        } catch (OrderConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->orderPayload($order)], 201);
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
                    'id' => $item->id,
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
