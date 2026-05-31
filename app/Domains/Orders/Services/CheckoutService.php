<?php

namespace App\Domains\Orders\Services;

use App\Domains\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /**
     * @return array<string, array<int, array{code: string, name: string}>>
     */
    public function options(): array
    {
        return [
            'payment_methods' => [
                ['code' => 'bank_card', 'name' => 'Банковская карта'],
                ['code' => 'sbp', 'name' => 'СБП'],
                ['code' => 'cash_on_delivery', 'name' => 'Оплата при получении'],
            ],
            'delivery_services' => [
                ['code' => 'stockflow_courier', 'name' => 'Курьер StockFlow'],
                ['code' => 'cdek', 'name' => 'СДЭК'],
                ['code' => 'russian_post', 'name' => 'Почта России'],
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $address
     * @param  array<int, array{delivery_service: string, items: array<int, array{order_item_id: int, quantity: int}>}>  $shipments
     */
    public function configure(int $orderId, array $address, string $paymentMethod, array $shipments): Order
    {
        return DB::transaction(function () use ($orderId, $address, $paymentMethod, $shipments): Order {
            /** @var Order $order */
            $order = Order::query()
                ->with('items')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== Order::STATUS_DRAFT) {
                throw OrderConflict::orderIsNotDraft($order->id);
            }

            $quantities = [];

            foreach ($shipments as $shipment) {
                foreach ($shipment['items'] as $item) {
                    $quantities[$item['order_item_id']] = ($quantities[$item['order_item_id']] ?? 0) + $item['quantity'];
                }
            }

            foreach ($order->items as $item) {
                if (($quantities[$item->id] ?? null) !== $item->quantity) {
                    throw OrderConflict::invalidShipmentItems();
                }

                unset($quantities[$item->id]);
            }

            if ($quantities !== []) {
                throw OrderConflict::invalidShipmentItems();
            }

            $order->shipments()->delete();

            foreach ($shipments as $shipmentPayload) {
                $shipment = $order->shipments()->create([
                    'delivery_service' => $shipmentPayload['delivery_service'],
                ]);

                $shipment->items()->createMany($shipmentPayload['items']);
            }

            $order->fill([
                'payment_method' => $paymentMethod,
                'recipient_name' => $address['recipient_name'],
                'recipient_phone' => $address['recipient_phone'],
                'delivery_country_code' => strtoupper((string) $address['country_code']),
                'delivery_city' => $address['city'],
                'delivery_postal_code' => $address['postal_code'],
                'delivery_address_line_1' => $address['address_line_1'],
                'delivery_address_line_2' => $address['address_line_2'] ?? null,
                'checkout_at' => now(),
            ])->save();

            return $this->load($order);
        });
    }

    public function find(int $orderId): Order
    {
        return $this->load(Order::query()->findOrFail($orderId));
    }

    private function load(Order $order): Order
    {
        return $order->load('items', 'shipments.items.orderItem');
    }
}
