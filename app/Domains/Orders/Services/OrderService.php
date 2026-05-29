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
use App\Infrastructure\Messaging\DomainEventRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DomainEventRecorder $events,
    ) {}

    public function createDraft(int $cartId): Order
    {
        return DB::transaction(function () use ($cartId): Order {
            /** @var Cart $cart */
            $cart = Cart::query()
                ->with('items.product')
                ->lockForUpdate()
                ->findOrFail($cartId);

            if ($cart->items->isEmpty()) {
                throw OrderConflict::emptyCart();
            }

            $prices = ProductPrice::query()
                ->whereIn('product_id', $cart->items->pluck('product_id'))
                ->where('price_type', 'retail')
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $currency = $this->resolveCurrency($cart->items, $prices);

            /** @var Order $order */
            $order = Order::query()->create([
                'cart_id' => $cart->id,
                'status' => Order::STATUS_DRAFT,
                'currency' => $currency,
                'total_amount_minor' => 0,
            ]);

            $total = 0;

            foreach ($cart->items as $cartItem) {
                /** @var ProductPrice $price */
                $price = $prices->get($cartItem->product_id);
                $lineAmount = $price->amount_minor * $cartItem->quantity;
                $total += $lineAmount;

                $order->items()->create([
                    'product_id' => $cartItem->product_id,
                    'sku' => $cartItem->product->sku,
                    'product_name' => $cartItem->product->name,
                    'quantity' => $cartItem->quantity,
                    'unit_amount_minor' => $price->amount_minor,
                    'currency' => $price->currency,
                    'line_amount_minor' => $lineAmount,
                ]);
            }

            $order->total_amount_minor = $total;
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
