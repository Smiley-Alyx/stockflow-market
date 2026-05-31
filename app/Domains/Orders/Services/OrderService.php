<?php

namespace App\Domains\Orders\Services;

use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Events\OrderCreated;
use App\Domains\Orders\Events\OrderExpired;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Events\OrderReservationFailed;
use App\Domains\Orders\Events\OrderReservationSucceeded;
use App\Domains\Orders\Models\Cart;
use App\Domains\Orders\Models\CartItem;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Pricing\Models\ProductPrice;
use App\Domains\Pricing\Models\Promotion;
use App\Infrastructure\Messaging\DomainEventRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DomainEventRecorder $events,
    ) {}

    /**
     * @param  array<int, int>|null  $productIds
     */
    public function createDraft(int $cartId, ?string $cityCode = null, ?string $promoCode = null, ?array $productIds = null): Order
    {
        $cityCode = $this->normalizeOptionalCode($cityCode, lowercase: true);
        $promoCode = $this->normalizeOptionalCode($promoCode);

        return DB::transaction(function () use ($cartId, $cityCode, $promoCode, $productIds): Order {
            /** @var Cart $cart */
            $cart = Cart::query()
                ->with('items.product')
                ->lockForUpdate()
                ->findOrFail($cartId);

            $items = $cart->items
                ->whereNull('removed_at')
                ->when($productIds !== null, fn (Collection $items): Collection => $items->whereIn('product_id', $productIds))
                ->values();

            if ($items->isEmpty()) {
                throw OrderConflict::emptyCart();
            }

            $prices = $this->activeCheckoutPrices($items->pluck('product_id')->all(), $cityCode);

            $currency = $this->resolveCurrency($items, $prices);
            $promotion = $promoCode === null ? null : $this->activePromotion($promoCode);

            /** @var Order $order */
            $order = Order::query()->create([
                'cart_id' => $cart->id,
                'status' => Order::STATUS_DRAFT,
                'city_code' => $cityCode,
                'promo_code' => $promotion?->code,
                'currency' => $currency,
                'subtotal_amount_minor' => 0,
                'discount_amount_minor' => 0,
                'total_amount_minor' => 0,
            ]);

            $subtotal = 0;

            foreach ($items as $cartItem) {
                /** @var ProductPrice $price */
                $price = $prices->get($cartItem->product_id);
                $lineAmount = $price->amount_minor * $cartItem->quantity;
                $subtotal += $lineAmount;

                $order->items()->create([
                    'product_id' => $cartItem->product_id,
                    'pricing_product_price_id' => $price->id,
                    'sku' => $cartItem->product->sku,
                    'product_name' => $cartItem->product->name,
                    'quantity' => $cartItem->quantity,
                    'price_type' => $price->price_type,
                    'price_city_code' => $price->city_code,
                    'price_version' => $price->price_version,
                    'price_active_from' => $price->active_from,
                    'unit_amount_minor' => $price->amount_minor,
                    'currency' => $price->currency,
                    'line_amount_minor' => $lineAmount,
                ]);
            }

            $discount = $this->discountAmount($promotion, $subtotal, $currency);

            $order->subtotal_amount_minor = $subtotal;
            $order->discount_amount_minor = $discount;
            $order->total_amount_minor = $subtotal - $discount;
            $order->save();

            return $order->load('items');
        });
    }

    public function confirm(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== Order::STATUS_DRAFT) {
                throw OrderConflict::orderIsNotDraft($locked->id);
            }

            $locked->status = Order::STATUS_RESERVATION_PENDING;
            $locked->save();

            $requested = $locked->load('items');

            $this->events->record(new OrderConfirmationRequested($requested), 'order', (string) $requested->id);

            return $requested;
        });
    }

    public function reserveInventory(int $orderId): Order
    {
        try {
            return DB::transaction(function () use ($orderId): Order {
                /** @var Order $locked */
                $locked = Order::query()
                    ->with('items')
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($locked->status, [
                    Order::STATUS_CONFIRMED,
                    Order::STATUS_RESERVATION_FAILED,
                    Order::STATUS_PAID,
                    Order::STATUS_CANCELLED,
                    Order::STATUS_EXPIRED,
                ], true)) {
                    return $locked;
                }

                if ($locked->status !== Order::STATUS_RESERVATION_PENDING) {
                    throw OrderConflict::orderIsNotDraft($locked->id);
                }

                foreach ($locked->items as $item) {
                    $this->reserveOrderItem($locked, $item);
                }

                $locked->status = Order::STATUS_CONFIRMED;
                $locked->confirmed_at = now();
                $locked->save();

                $confirmed = $locked->load('items');

                $this->events->record(new OrderReservationSucceeded($confirmed), 'order', (string) $confirmed->id);
                $this->events->record(new OrderCreated($confirmed), 'order', (string) $confirmed->id);

                return $confirmed;
            });
        } catch (InsufficientStock $exception) {
            return $this->markReservationFailed($orderId, $exception->getMessage());
        }
    }

    public function pay(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $order */
            $order = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === Order::STATUS_PAID) {
                return $order;
            }

            if ($order->status !== Order::STATUS_CONFIRMED) {
                throw OrderConflict::invalidStatusTransition($order->id, $order->status, Order::STATUS_PAID);
            }

            foreach ($this->orderReservations($order) as $reservation) {
                $consumed = $this->inventory->consumeReservation($reservation->idempotency_key);

                if ($consumed->status !== Reservation::STATUS_CONSUMED) {
                    throw OrderConflict::invalidStatusTransition($order->id, $order->status, Order::STATUS_PAID);
                }
            }

            $order->status = Order::STATUS_PAID;
            $order->paid_at = now();
            $order->save();

            $paid = $order->load('items');

            $this->events->record(new OrderPaid($paid), 'order', (string) $paid->id);

            return $paid;
        });
    }

    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $order */
            $order = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === Order::STATUS_CANCELLED) {
                return $order;
            }

            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_EXPIRED], true)) {
                throw OrderConflict::invalidStatusTransition($order->id, $order->status, Order::STATUS_CANCELLED);
            }

            foreach ($this->orderReservations($order) as $reservation) {
                $this->inventory->cancelReservation($reservation->idempotency_key);
            }

            $order->status = Order::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->save();

            $cancelled = $order->load('items');

            $this->events->record(new OrderCancelled($cancelled), 'order', (string) $cancelled->id);

            return $cancelled;
        });
    }

    public function expire(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $order */
            $order = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === Order::STATUS_EXPIRED) {
                return $order;
            }

            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_CANCELLED], true)) {
                throw OrderConflict::invalidStatusTransition($order->id, $order->status, Order::STATUS_EXPIRED);
            }

            foreach ($this->orderReservations($order) as $reservation) {
                $this->inventory->expireReservation($reservation->idempotency_key);
            }

            $order->status = Order::STATUS_EXPIRED;
            $order->expired_at = now();
            $order->save();

            $expired = $order->load('items');

            $this->events->record(new OrderExpired($expired), 'order', (string) $expired->id);

            return $expired;
        });
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  Collection<int, ProductPrice>  $prices
     */
    private function resolveCurrency(Collection $items, Collection $prices): string
    {
        $currencies = [];

        foreach ($items as $item) {
            $price = $prices->get($item->product_id);

            if ($price === null) {
                throw OrderConflict::missingActivePrice($item->product_id);
            }

            $currencies[$price->currency] = true;
        }

        if (count($currencies) !== 1) {
            throw OrderConflict::mixedCurrencies();
        }

        return array_key_first($currencies);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return Collection<int, ProductPrice>
     */
    private function activeCheckoutPrices(array $productIds, ?string $cityCode): Collection
    {
        $now = now();

        return ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('price_type', ['retail', 'sale'])
            ->when(
                $cityCode !== null,
                fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                    ->whereNull('city_code')
                    ->orWhere('city_code', $cityCode)
                ),
                fn (Builder $query): Builder => $query->whereNull('city_code'),
            )
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('active_from')
                ->orWhere('active_from', '<=', $now)
            )
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('active_until')
                ->orWhere('active_until', '>', $now)
            )
            ->orderBy('product_id')
            ->orderByRaw('case when city_code is null then 1 else 0 end')
            ->orderByDesc('active_from')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get()
            ->unique(fn (ProductPrice $price): string => $price->product_id.'|'.$price->price_type)
            ->groupBy('product_id')
            ->map(function (Collection $prices): ProductPrice {
                /** @var ProductPrice|null $retail */
                $retail = $prices->firstWhere('price_type', 'retail');
                /** @var ProductPrice|null $sale */
                $sale = $prices->firstWhere('price_type', 'sale');
                $original = $retail ?? $sale;

                return $sale !== null
                    && $original !== null
                    && $sale->currency === $original->currency
                    && $sale->amount_minor < $original->amount_minor
                        ? $sale
                        : $original;
            })
            ->keyBy('product_id');
    }

    private function activePromotion(string $promoCode): Promotion
    {
        $now = now();

        /** @var Promotion|null $promotion */
        $promotion = Promotion::query()
            ->whereRaw('upper(code) = ?', [strtoupper($promoCode)])
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $now)
            )
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $now)
            )
            ->lockForUpdate()
            ->first();

        if ($promotion === null) {
            throw OrderConflict::invalidPromotion(strtoupper($promoCode));
        }

        return $promotion;
    }

    private function discountAmount(?Promotion $promotion, int $subtotal, string $currency): int
    {
        if ($promotion === null || $subtotal === 0) {
            return 0;
        }

        if ($promotion->currency !== null && $promotion->currency !== $currency) {
            throw OrderConflict::invalidPromotion($promotion->code);
        }

        $discount = match ($promotion->discount_type) {
            Promotion::TYPE_FIXED_AMOUNT => $promotion->discount_value,
            Promotion::TYPE_PERCENT => intdiv($subtotal * min($promotion->discount_value, 100), 100),
            default => throw OrderConflict::invalidPromotion($promotion->code),
        };

        return min($discount, $subtotal);
    }

    private function normalizeOptionalCode(?string $code, bool $lowercase = false): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return $lowercase ? strtolower($code) : $code;
    }

    private function reserveOrderItem(Order $order, OrderItem $item): void
    {
        $remaining = $item->quantity;
        $stockItems = StockItem::query()
            ->where('product_id', $item->product_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($stockItems as $stockItem) {
            $quantity = min($remaining, $stockItem->availableQuantity());

            if ($quantity < 1) {
                continue;
            }

            $this->inventory->reserve(
                $stockItem,
                $quantity,
                "order:{$order->id}:item:{$item->id}:stock:{$stockItem->id}",
                now()->addMinutes(15),
                'order',
                (string) $order->id,
                ['order_item_id' => $item->id],
            );

            $remaining -= $quantity;

            if ($remaining === 0) {
                return;
            }
        }

        throw InsufficientStock::available($item->sku, $item->quantity, $item->quantity - $remaining);
    }

    private function markReservationFailed(int $orderId, string $reason): Order
    {
        return DB::transaction(function () use ($orderId, $reason): Order {
            /** @var Order $order */
            $order = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $order->status = Order::STATUS_RESERVATION_FAILED;
            $order->save();

            $this->events->record(new OrderReservationFailed($order, $reason), 'order', (string) $order->id);

            return $order;
        });
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function orderReservations(Order $order): Collection
    {
        return Reservation::query()
            ->where('idempotency_key', 'like', "order:{$order->id}:item:%")
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
