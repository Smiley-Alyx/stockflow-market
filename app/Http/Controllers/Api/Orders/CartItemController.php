<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domains\Orders\Models\Cart;
use App\Domains\Orders\Services\CartService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartItemController extends Controller
{
    public function store(Request $request, CartService $carts): JsonResponse
    {
        $payload = $request->validate([
            'cart_id' => ['nullable', 'integer', 'min:1', 'exists:orders_carts,id'],
            'product_id' => ['required', 'integer', 'min:1', 'exists:catalog_products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $cart = $carts->addItem(
            cartId: isset($payload['cart_id']) ? (int) $payload['cart_id'] : null,
            productId: (int) $payload['product_id'],
            quantity: (int) $payload['quantity'],
        );

        return response()->json(['data' => $this->cartPayload($cart)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPayload(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'items' => $cart->items
                ->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'sku' => $item->product?->sku,
                    'product_name' => $item->product?->name,
                    'quantity' => $item->quantity,
                ])
                ->values()
                ->all(),
        ];
    }
}
