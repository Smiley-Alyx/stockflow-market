<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\CheckoutService;
use App\Domains\Orders\Services\OrderConflict;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function options(CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->options()]);
    }

    public function show(int $id, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $this->payload($checkout->find($id), $checkout)]);
    }

    public function update(int $id, Request $request, CheckoutService $checkout): JsonResponse
    {
        $options = $checkout->options();
        $payload = $request->validate([
            'payment_method' => ['required', 'string', Rule::in(array_column($options['payment_methods'], 'code'))],
            'address' => ['required', 'array'],
            'address.recipient_name' => ['required', 'string', 'max:255'],
            'address.recipient_phone' => ['required', 'string', 'max:255'],
            'address.country_code' => ['required', 'string', 'size:2'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.postal_code' => ['required', 'string', 'max:32'],
            'address.address_line_1' => ['required', 'string', 'max:255'],
            'address.address_line_2' => ['nullable', 'string', 'max:255'],
            'shipments' => ['required', 'array', 'min:1', 'max:100'],
            'shipments.*.delivery_service' => ['required', 'string', Rule::in(array_column($options['delivery_services'], 'code'))],
            'shipments.*.items' => ['required', 'array', 'min:1', 'max:100'],
            'shipments.*.items.*.order_item_id' => ['required', 'integer', 'distinct', 'min:1'],
            'shipments.*.items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $order = $checkout->configure(
                $id,
                $payload['address'],
                $payload['payment_method'],
                $payload['shipments'],
            );
        } catch (OrderConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->payload($order, $checkout)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order, CheckoutService $checkout): array
    {
        return [
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'payment_method' => $order->payment_method,
                'checkout_at' => $order->checkout_at?->toJSON(),
                'address' => [
                    'recipient_name' => $order->recipient_name,
                    'recipient_phone' => $order->recipient_phone,
                    'country_code' => $order->delivery_country_code,
                    'city' => $order->delivery_city,
                    'postal_code' => $order->delivery_postal_code,
                    'address_line_1' => $order->delivery_address_line_1,
                    'address_line_2' => $order->delivery_address_line_2,
                ],
                'total_amount_minor' => $order->total_amount_minor,
                'currency' => $order->currency,
                'items' => $order->items
                    ->map(fn ($item): array => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'sku' => $item->sku,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_amount_minor' => $item->unit_amount_minor,
                        'line_amount_minor' => $item->line_amount_minor,
                        'currency' => $item->currency,
                    ])
                    ->values()
                    ->all(),
                'reservations' => $order->checkoutSaga?->reservations
                    ->map(fn ($reservation): array => [
                        'reservation_id' => $reservation->reservation_id,
                        'order_item_id' => $reservation->order_item_id,
                        'status' => $reservation->status,
                        'updated_at' => $reservation->updated_at?->toJSON(),
                    ])
                    ->values()
                    ->all() ?? [],
                'shipments' => $order->shipments
                    ->map(fn ($shipment): array => [
                        'id' => $shipment->id,
                        'delivery_service' => $shipment->delivery_service,
                        'items' => $shipment->items
                            ->map(fn ($item): array => [
                                'order_item_id' => $item->order_item_id,
                                'quantity' => $item->quantity,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
            'options' => $checkout->options(),
        ];
    }
}
