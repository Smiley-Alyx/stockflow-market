<?php

namespace App\Domains\Orders\Services;

use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Orders\Events\InventoryReservationFailed;
use App\Domains\Orders\Events\InventoryReserved;
use App\Domains\Orders\Events\InventoryReserveRequested;
use App\Domains\Orders\Events\OrderCreated;
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
        /** @var Order $order */
        $order = Order::query()->with('items')->findOrFail($orderId);

        $this->events->record(new InventoryReserveRequested($order), 'order', (string) $order->id);

        try {
            $confirmed = DB::transaction(function () use ($orderId): Order {
                /** @var Order $locked */
                $locked = Order::query()
                    ->with('items')
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== Order::STATUS_DRAFT) {
                    throw OrderConflict::orderIsNotDraft($locked->id);
                }

                foreach ($locked->items as $item) {
                    $this->reserveOrderItem($locked, $item);
                }

                $locked->status = Order::STATUS_CONFIRMED;
                $locked->confirmed_at = now();
                $locked->save();

                $confirmed = $locked->load('items');

                $this->events->record(new InventoryReserved($confirmed), 'order', (string) $confirmed->id);
                $this->events->record(new OrderCreated($confirmed), 'order', (string) $confirmed->id);

                return $confirmed;
            });
        } catch (InsufficientStock $exception) {
            $this->markReservationFailed($orderId, $exception->getMessage());

            throw $exception;
        }

        return $confirmed;
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

            $this->events->record(new InventoryReservationFailed($order, $reason), 'order', (string) $order->id);

            return $order;
        });
    }
}
