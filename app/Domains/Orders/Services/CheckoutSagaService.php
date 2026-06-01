<?php

namespace App\Domains\Orders\Services;

use App\Domains\Orders\Events\OrderCreated;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Events\OrderReservationFailed;
use App\Domains\Orders\Events\OrderReservationSucceeded;
use App\Domains\Orders\Models\CheckoutSaga;
use App\Domains\Orders\Models\CheckoutSagaReservation;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\Shipment;
use App\Infrastructure\Messaging\DomainEventRecorder;
use App\Infrastructure\Messaging\ProviderMessageRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutSagaService
{
    public function __construct(
        private readonly ProviderMessageRecorder $messages,
        private readonly DomainEventRecorder $events,
    ) {}

    public function start(int $orderId): CheckoutSaga
    {
        return DB::transaction(function () use ($orderId): CheckoutSaga {
            /** @var CheckoutSaga|null $existing */
            $existing = CheckoutSaga::query()->where('order_id', $orderId)->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var Order $order */
            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($orderId);
            $correlationId = (string) Str::uuid();

            /** @var CheckoutSaga $saga */
            $saga = CheckoutSaga::query()->create([
                'order_id' => $order->id,
                'correlation_id' => $correlationId,
                'payment_id' => 'pay_'.$order->id,
                'status' => CheckoutSaga::STATUS_RESERVING_STOCK,
            ]);

            foreach ($order->items as $item) {
                $reservationId = 'res-'.$order->id.'-'.$item->id;

                $saga->reservations()->create([
                    'order_item_id' => $item->id,
                    'reservation_id' => $reservationId,
                    'status' => CheckoutSagaReservation::STATUS_PENDING,
                ]);

                $this->messages->record(
                    exchange: 'stockflow.inventory',
                    routingKey: 'inventory.reservation.requested.v1',
                    correlationId: $correlationId,
                    idempotencyKey: 'reserve:'.$reservationId,
                    payload: [
                        'reservation_id' => $reservationId,
                        'order_id' => (string) $order->id,
                        'sku' => $item->sku,
                        'quantity' => $item->quantity,
                    ],
                );
            }

            return $saga->load('reservations');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $routingKey, string $correlationId, string $causationId, array $payload): void
    {
        DB::transaction(function () use ($routingKey, $correlationId, $causationId, $payload): void {
            /** @var CheckoutSaga $saga */
            $saga = CheckoutSaga::query()
                ->with('order.items', 'order.shipments', 'reservations')
                ->where('correlation_id', $correlationId)
                ->lockForUpdate()
                ->firstOrFail();

            match ($routingKey) {
                'inventory.reservation.confirmed.v1' => $this->reservationConfirmed($saga, $causationId, $payload),
                'inventory.reservation.rejected.v1' => $this->fail($saga, $causationId, (string) ($payload['reason'] ?? 'inventory reservation rejected')),
                'inventory.reservation.released.v1' => $this->reservationReleased($saga, $payload),
                'inventory.reservation.release_failed.v1' => $this->reservationReleaseFailed($saga, $payload),
                'payment.authorization.approved.v1' => $this->authorizationApproved($saga, $causationId, $payload),
                'payment.authorization.declined.v1' => $this->fail($saga, $causationId, (string) ($payload['reason_code'] ?? 'payment authorization declined')),
                'payment.capture.completed.v1' => $this->captureCompleted($saga, $causationId, $payload),
                'payment.capture.failed.v1' => $this->fail($saga, $causationId, (string) ($payload['reason_code'] ?? 'payment capture failed')),
                'payment.refund.completed.v1' => $this->refundCompleted($saga, $payload),
                'payment.refund.failed.v1' => $this->refundFailed($saga, $payload),
                'delivery.shipment.created.v1' => $this->shipmentCreated($saga, $causationId, $payload),
                'delivery.shipment.creation_failed.v1' => $this->fail($saga, $causationId, (string) ($payload['failure_code'] ?? 'shipment creation failed')),
                'delivery.shipment.status_changed.v1' => $this->shipmentStatusChanged($saga, $payload),
                'delivery.shipment.cancelled.v1' => $this->shipmentCancelled($saga, $payload),
                'delivery.shipment.cancel_failed.v1' => $this->shipmentCancelFailed($saga, $payload),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reservationConfirmed(CheckoutSaga $saga, string $causationId, array $payload): void
    {
        /** @var CheckoutSagaReservation $reservation */
        $reservation = $saga->reservations->firstWhere('reservation_id', (string) $payload['reservation_id']);

        if ($reservation->status !== CheckoutSagaReservation::STATUS_PENDING) {
            return;
        }

        $reservation->update(['status' => CheckoutSagaReservation::STATUS_CONFIRMED]);
        $saga->load('reservations');

        if ($saga->reservations->contains(fn (CheckoutSagaReservation $item): bool => $item->status !== CheckoutSagaReservation::STATUS_CONFIRMED)) {
            return;
        }

        $saga->update(['status' => CheckoutSaga::STATUS_AUTHORIZING_PAYMENT]);

        $this->messages->record(
            exchange: 'stockflow.payment',
            routingKey: 'payment.authorization.requested.v1',
            correlationId: $saga->correlation_id,
            causationId: $causationId,
            idempotencyKey: 'authorize:'.$saga->payment_id,
            payload: [
                'payment_id' => $saga->payment_id,
                'order_id' => (string) $saga->order_id,
                'customer_id' => 'customer:cart:'.$saga->order->cart_id,
                'amount' => $this->amount($saga->order),
                'payment_method' => [
                    'type' => 'card',
                    'token' => (string) config('stockflow.provider_saga.payment_token'),
                ],
                'capture_mode' => 'manual',
                'metadata' => ['checkout_id' => 'checkout:'.$saga->order_id],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function authorizationApproved(CheckoutSaga $saga, string $causationId, array $payload): void
    {
        if ($saga->status !== CheckoutSaga::STATUS_AUTHORIZING_PAYMENT) {
            return;
        }

        $order = $saga->order;
        $order->update([
            'status' => Order::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $saga->update([
            'status' => CheckoutSaga::STATUS_CAPTURING_PAYMENT,
            'authorization_id' => (string) $payload['authorization_id'],
        ]);

        $this->events->record(new OrderReservationSucceeded($order), 'order', (string) $order->id);
        $this->events->record(new OrderCreated($order), 'order', (string) $order->id);

        $this->messages->record(
            exchange: 'stockflow.payment',
            routingKey: 'payment.capture.requested.v1',
            correlationId: $saga->correlation_id,
            causationId: $causationId,
            idempotencyKey: 'capture:'.$saga->payment_id,
            payload: [
                'payment_id' => $saga->payment_id,
                'amount' => $this->amount($order),
                'metadata' => ['order_id' => (string) $order->id],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function captureCompleted(CheckoutSaga $saga, string $causationId, array $payload): void
    {
        if ($saga->status !== CheckoutSaga::STATUS_CAPTURING_PAYMENT) {
            return;
        }

        $order = $saga->order;
        $order->update([
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $saga->update([
            'status' => CheckoutSaga::STATUS_CREATING_SHIPMENTS,
            'capture_id' => (string) $payload['capture_id'],
        ]);

        $this->events->record(new OrderPaid($order), 'order', (string) $order->id);

        if ($order->shipments->isEmpty()) {
            $saga->update(['status' => CheckoutSaga::STATUS_COMPLETED]);

            return;
        }

        foreach ($order->shipments as $shipment) {
            $providerShipmentId = 'shp_'.$order->id.'_'.$shipment->id;
            $shipment->update([
                'provider_shipment_id' => $providerShipmentId,
                'provider_status' => 'pending',
            ]);

            $this->messages->record(
                exchange: 'stockflow.delivery',
                routingKey: 'delivery.shipment.requested.v1',
                correlationId: $saga->correlation_id,
                causationId: $causationId,
                idempotencyKey: 'shipment:'.$providerShipmentId,
                payload: [
                    'shipment_id' => $providerShipmentId,
                    'order_id' => (string) $order->id,
                    'delivery_address' => [
                        'recipient_name' => $order->recipient_name ?? 'StockFlow customer',
                        'country_code' => $order->delivery_country_code ?? 'RU',
                        'city' => $order->delivery_city ?? 'Moscow',
                        'postal_code' => $order->delivery_postal_code ?? '101000',
                        'street_line1' => $order->delivery_address_line_1 ?? 'Unknown address',
                        'street_line2' => $order->delivery_address_line_2,
                        'phone' => $order->recipient_phone ?? '+79990000000',
                    ],
                    'carrier_profile' => [
                        'carrier_code' => $shipment->delivery_service,
                        'service_level' => 'standard',
                    ],
                    'metadata' => ['checkout_id' => 'checkout:'.$order->id],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentCreated(CheckoutSaga $saga, string $causationId, array $payload): void
    {
        if (! in_array($saga->status, [CheckoutSaga::STATUS_CREATING_SHIPMENTS, CheckoutSaga::STATUS_FAILED], true)) {
            return;
        }

        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()
            ->where('order_id', $saga->order_id)
            ->where('provider_shipment_id', (string) $payload['shipment_id'])
            ->first();

        if ($shipment === null || ! in_array($shipment->provider_status, ['pending', 'created'], true)) {
            return;
        }

        $shipment->update([
            'provider_status' => 'created',
            'tracking_number' => $payload['tracking_number'] ?? null,
        ]);

        if ($saga->status === CheckoutSaga::STATUS_FAILED) {
            $this->requestShipmentCancellation($saga, $shipment, $causationId);

            return;
        }

        $pending = Shipment::query()
            ->where('order_id', $saga->order_id)
            ->where('provider_status', '!=', 'created')
            ->exists();

        if (! $pending) {
            $saga->update(['status' => CheckoutSaga::STATUS_COMPLETED]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reservationReleased(CheckoutSaga $saga, array $payload): void
    {
        $saga->reservations()
            ->where('reservation_id', (string) $payload['reservation_id'])
            ->update(['status' => CheckoutSagaReservation::STATUS_RELEASED]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reservationReleaseFailed(CheckoutSaga $saga, array $payload): void
    {
        $saga->reservations()
            ->where('reservation_id', (string) $payload['reservation_id'])
            ->update(['status' => CheckoutSagaReservation::STATUS_RELEASE_FAILED]);

        $this->recordCompensationFailure($saga, (string) ($payload['reason'] ?? 'inventory reservation release failed'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refundCompleted(CheckoutSaga $saga, array $payload): void
    {
        if ($saga->refund_status !== CheckoutSaga::REFUND_STATUS_PENDING) {
            return;
        }

        $saga->update([
            'refund_id' => (string) $payload['refund_id'],
            'refund_status' => CheckoutSaga::REFUND_STATUS_COMPLETED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refundFailed(CheckoutSaga $saga, array $payload): void
    {
        if ($saga->refund_status !== CheckoutSaga::REFUND_STATUS_PENDING) {
            return;
        }

        $saga->update(['refund_status' => CheckoutSaga::REFUND_STATUS_FAILED]);
        $this->recordCompensationFailure($saga, (string) ($payload['reason_code'] ?? 'payment refund failed'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentCancelled(CheckoutSaga $saga, array $payload): void
    {
        Shipment::query()
            ->where('order_id', $saga->order_id)
            ->where('provider_shipment_id', (string) $payload['shipment_id'])
            ->where('provider_status', 'cancel_pending')
            ->update(['provider_status' => 'cancelled']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentStatusChanged(CheckoutSaga $saga, array $payload): void
    {
        $changes = ['provider_status' => (string) $payload['current_status']];

        if (array_key_exists('tracking_number', $payload)) {
            $changes['tracking_number'] = $payload['tracking_number'];
        }

        Shipment::query()
            ->where('order_id', $saga->order_id)
            ->where('provider_shipment_id', (string) $payload['shipment_id'])
            ->whereNotIn('provider_status', ['cancel_pending', 'cancelled', 'cancel_failed'])
            ->update($changes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentCancelFailed(CheckoutSaga $saga, array $payload): void
    {
        Shipment::query()
            ->where('order_id', $saga->order_id)
            ->where('provider_shipment_id', (string) $payload['shipment_id'])
            ->where('provider_status', 'cancel_pending')
            ->update(['provider_status' => 'cancel_failed']);

        $this->recordCompensationFailure($saga, (string) ($payload['failure_code'] ?? 'shipment cancellation failed'));
    }

    private function fail(CheckoutSaga $saga, string $causationId, string $reason): void
    {
        if ($saga->status === CheckoutSaga::STATUS_FAILED) {
            return;
        }

        $saga->update([
            'status' => CheckoutSaga::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);

        if (! in_array($saga->order->status, [Order::STATUS_PAID, Order::STATUS_CANCELLED, Order::STATUS_EXPIRED], true)) {
            $saga->order->update(['status' => Order::STATUS_RESERVATION_FAILED]);
            $this->events->record(new OrderReservationFailed($saga->order, $reason), 'order', (string) $saga->order_id);
        }

        foreach ($saga->reservations->where('status', CheckoutSagaReservation::STATUS_CONFIRMED) as $reservation) {
            $reservation->update(['status' => CheckoutSagaReservation::STATUS_RELEASE_PENDING]);

            $this->messages->record(
                exchange: 'stockflow.inventory',
                routingKey: 'inventory.reservation.release.requested.v1',
                correlationId: $saga->correlation_id,
                causationId: $causationId,
                idempotencyKey: 'release:'.$reservation->reservation_id,
                payload: [
                    'reservation_id' => $reservation->reservation_id,
                    'reason' => 'checkout_saga_failed',
                ],
            );
        }

        foreach ($saga->order->shipments->where('provider_status', 'created') as $shipment) {
            $this->requestShipmentCancellation($saga, $shipment, $causationId);
        }

        if ($saga->capture_id !== null && $saga->refund_status === null) {
            $saga->update(['refund_status' => CheckoutSaga::REFUND_STATUS_PENDING]);

            $this->messages->record(
                exchange: 'stockflow.payment',
                routingKey: 'payment.refund.requested.v1',
                correlationId: $saga->correlation_id,
                causationId: $causationId,
                idempotencyKey: 'refund:'.$saga->payment_id,
                payload: [
                    'payment_id' => $saga->payment_id,
                    'amount' => $this->amount($saga->order),
                    'metadata' => ['order_id' => (string) $saga->order_id],
                ],
            );
        }
    }

    private function requestShipmentCancellation(CheckoutSaga $saga, Shipment $shipment, string $causationId): void
    {
        if ($shipment->provider_status !== 'created') {
            return;
        }

        $shipment->update(['provider_status' => 'cancel_pending']);

        $this->messages->record(
            exchange: 'stockflow.delivery',
            routingKey: 'delivery.shipment.cancel_requested.v1',
            correlationId: $saga->correlation_id,
            causationId: $causationId,
            idempotencyKey: 'shipment-cancel:'.$shipment->provider_shipment_id,
            payload: [
                'shipment_id' => $shipment->provider_shipment_id,
                'order_id' => (string) $saga->order_id,
                'reason' => 'checkout_saga_failed',
                'metadata' => ['checkout_id' => 'checkout:'.$saga->order_id],
            ],
        );
    }

    private function recordCompensationFailure(CheckoutSaga $saga, string $reason): void
    {
        $saga->update([
            'compensation_failure_reason' => $saga->compensation_failure_reason === null
                ? $reason
                : $saga->compensation_failure_reason.'; '.$reason,
        ]);
    }

    /**
     * @return array{value: int, currency: string}
     */
    private function amount(Order $order): array
    {
        return [
            'value' => $order->total_amount_minor,
            'currency' => $order->currency,
        ];
    }
}
