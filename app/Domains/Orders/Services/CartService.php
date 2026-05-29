<?php

namespace App\Domains\Orders\Services;

use App\Domains\Orders\Models\Cart;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function addItem(?int $cartId, int $productId, int $quantity): Cart
    {
        return DB::transaction(function () use ($cartId, $productId, $quantity): Cart {
            /** @var Cart $cart */
            $cart = $cartId === null
                ? Cart::query()->create()
                : Cart::query()->lockForUpdate()->findOrFail($cartId);

            $item = $cart->items()
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                $cart->items()->create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ]);
            } else {
                $item->quantity += $quantity;
                $item->save();
            }

            return $cart->load('items.product');
        });
    }
}
