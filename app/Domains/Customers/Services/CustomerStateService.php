<?php

namespace App\Domains\Customers\Services;

use App\Domains\Customers\Models\Favorite;
use App\Domains\Orders\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerStateService
{
    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $cartItems
     * @param  array<int, int>  $favoriteProductIds
     * @return array<string, mixed>
     */
    public function merge(User $user, array $cartItems, array $favoriteProductIds): array
    {
        return DB::transaction(function () use ($user, $cartItems, $favoriteProductIds): array {
            $cart = $this->cart($user);

            foreach ($cartItems as $cartItem) {
                $item = $cart->items()
                    ->where('product_id', $cartItem['product_id'])
                    ->lockForUpdate()
                    ->first();

                if ($item === null) {
                    $cart->items()->create($cartItem);
                } else {
                    $item->quantity += $cartItem['quantity'];
                    $item->save();
                }
            }

            foreach ($favoriteProductIds as $productId) {
                Favorite::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'product_id' => $productId,
                ]);
            }

            return $this->state($user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setCartItem(User $user, int $productId, int $quantity): array
    {
        $this->cart($user)->items()->updateOrCreate(
            ['product_id' => $productId],
            ['quantity' => $quantity],
        );

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeCartItem(User $user, int $productId): array
    {
        $this->cart($user)->items()->where('product_id', $productId)->delete();

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function addFavorite(User $user, int $productId): array
    {
        Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'product_id' => $productId,
        ]);

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeFavorite(User $user, int $productId): array
    {
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->delete();

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(User $user): array
    {
        $cart = $this->cart($user)->load('items.product');
        $favorites = Favorite::query()
            ->with('product')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        return [
            'cart' => [
                'id' => $cart->id,
                'items' => $cart->items
                    ->map(fn ($item): array => $this->productPayload($item->product, [
                        'quantity' => $item->quantity,
                    ]))
                    ->values()
                    ->all(),
            ],
            'favorites' => $favorites
                ->map(fn (Favorite $favorite): array => $this->productPayload($favorite->product))
                ->values()
                ->all(),
        ];
    }

    private function cart(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    private function productPayload($product, array $additional = []): array
    {
        return array_merge([
            'product_id' => $product?->id,
            'slug' => $product?->slug,
            'sku' => $product?->sku,
            'product_name' => $product?->name,
        ], $additional);
    }
}
