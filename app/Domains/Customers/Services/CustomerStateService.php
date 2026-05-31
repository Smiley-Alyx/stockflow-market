<?php

namespace App\Domains\Customers\Services;

use App\Domains\Customers\Models\Favorite;
use App\Domains\Orders\Models\Cart;
use App\Domains\Orders\Models\CartItem;
use App\Domains\Pricing\Read\PricingReadService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerStateService
{
    public function __construct(
        private readonly PricingReadService $pricing,
    ) {}

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
                    $cart->items()->create(array_merge($cartItem, ['is_selected' => true]));
                } else {
                    $item->quantity += $cartItem['quantity'];
                    $item->is_selected = true;
                    $item->removed_at = null;
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
            ['quantity' => $quantity, 'is_selected' => true, 'removed_at' => null],
        );

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeCartItem(User $user, int $productId): array
    {
        $this->cart($user)->items()->where('product_id', $productId)->update([
            'is_selected' => false,
            'removed_at' => now(),
        ]);

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreCartItem(User $user, int $productId): array
    {
        $this->cart($user)->items()->where('product_id', $productId)->update([
            'is_selected' => true,
            'removed_at' => null,
        ]);

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function selectCartItem(User $user, int $productId, bool $selected): array
    {
        $this->cart($user)->items()
            ->where('product_id', $productId)
            ->whereNull('removed_at')
            ->update(['is_selected' => $selected]);

        return $this->state($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function selectAllCartItems(User $user, bool $selected): array
    {
        $this->cart($user)->items()
            ->whereNull('removed_at')
            ->update(['is_selected' => $selected]);

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
        $cart = $this->cart($user)->load('items.product.imageFile');
        $prices = $this->pricing->catalogPricesForProductIds($cart->items->pluck('product_id')->all());
        $activeItems = $cart->items->whereNull('removed_at')->values();
        $removedItems = $cart->items->whereNotNull('removed_at')->values();
        $favorites = Favorite::query()
            ->with('product')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        return [
            'cart' => [
                'id' => $cart->id,
                'items' => $this->cartItemPayloads($activeItems, $prices),
                'removed_items' => $this->cartItemPayloads($removedItems, $prices),
                'summary' => $this->cartSummary($activeItems, $prices),
            ],
            'favorites' => $favorites
                ->map(fn (Favorite $favorite): array => $this->productPayload($favorite->product))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<int, array<string, mixed>>  $prices
     * @return array<int, array<string, mixed>>
     */
    private function cartItemPayloads(Collection $items, array $prices): array
    {
        return $items
            ->map(function (CartItem $item) use ($prices): array {
                $price = $prices[$item->product_id] ?? null;

                return $this->productPayload($item->product, [
                    'quantity' => $item->quantity,
                    'is_selected' => $item->is_selected,
                    'removed_at' => $item->removed_at?->toJSON(),
                    'price' => $price,
                    'line_amount_minor' => $price === null ? null : $price['amount_minor'] * $item->quantity,
                ]);
            })
            ->all();
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<int, array<string, mixed>>  $prices
     * @return array<string, mixed>
     */
    private function cartSummary(Collection $items, array $prices): array
    {
        $pricedItems = $items->filter(fn (CartItem $item): bool => isset($prices[$item->product_id]));
        $selectedItems = $pricedItems->where('is_selected', true);
        $currencies = $pricedItems
            ->map(fn (CartItem $item): string => $prices[$item->product_id]['currency'])
            ->unique();

        return [
            'items_count' => $items->sum('quantity'),
            'selected_items_count' => $items->where('is_selected', true)->sum('quantity'),
            'amount_minor' => $pricedItems->sum(fn (CartItem $item): int => $prices[$item->product_id]['amount_minor'] * $item->quantity),
            'selected_amount_minor' => $selectedItems->sum(fn (CartItem $item): int => $prices[$item->product_id]['amount_minor'] * $item->quantity),
            'currency' => $currencies->count() === 1 ? $currencies->first() : null,
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
            'image_url' => $product?->imageFile?->url(),
        ], $additional);
    }
}
