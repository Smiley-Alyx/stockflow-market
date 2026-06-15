<?php

namespace Tests\Feature;

use App\Domains\Orders\Models\CheckoutSaga;
use App\Domains\Orders\Models\CheckoutSagaReservation;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\CheckoutSagaService;
use App\Infrastructure\Messaging\ProviderOutboxMessage;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeProcessor;
use App\Infrastructure\Observability\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutSagaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_saga_runs_checkout_to_created_shipment_idempotently(): void
    {
        $order = $this->orderWithItems();
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservation = $saga->reservations->firstOrFail();

        $reservationMessage = $this->assertProviderMessage('inventory.reservation.requested.v1');
        $this->assertSame($reservationMessage->message_id, $reservationMessage->causation_id);
        $this->assertSame($reservationMessage->message_id, $reservationMessage->headers['causation_id']);
        $this->assertSame(1, $reservationMessage->headers['schema_version']);
        $this->assertSame(0, $reservationMessage->headers['retry_count']);

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservation->reservation_id,
        ]);
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CONFIRMED,
        ]);
        $authorizationMessage = $this->assertProviderMessage('payment.authorization.requested.v1');
        $this->assertSame('v1', $authorizationMessage->headers['schema_version']);

        $this->outcome($saga, 'payment.authorization.approved.v1', [
            'authorization_id' => 'auth_demo_001',
        ], 'msg_demo_auth_001');
        $captureMessage = $this->assertProviderMessage('payment.capture.requested.v1');
        $this->assertSame('msg_demo_auth_001', $captureMessage->causation_id);

        $this->outcome($saga, 'payment.capture.completed.v1', [
            'capture_id' => 'cap_demo_001',
        ]);
        $shipmentMessage = $this->assertProviderMessage('delivery.shipment.requested.v1');
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PAID,
        ]);

        $messageId = (string) Str::uuid();
        $this->outcome($saga, 'delivery.shipment.created.v1', [
            'shipment_id' => $shipmentMessage->payload['shipment_id'],
            'tracking_number' => 'TRK-DEMO-001',
        ], $messageId);
        $this->outcome($saga, 'delivery.shipment.created.v1', [
            'shipment_id' => $shipmentMessage->payload['shipment_id'],
            'tracking_number' => 'TRK-DEMO-001',
        ], $messageId);
        $this->outcome($saga, 'delivery.shipment.status_changed.v1', [
            'shipment_id' => $shipmentMessage->payload['shipment_id'],
            'current_status' => 'label_generated',
            'tracking_number' => 'TRK-DEMO-002',
        ]);

        $this->assertDatabaseHas('orders_checkout_sagas', [
            'id' => $saga->id,
            'status' => CheckoutSaga::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('orders_shipments', [
            'order_id' => $order->id,
            'provider_status' => 'label_generated',
            'tracking_number' => 'TRK-DEMO-002',
        ]);
        $this->assertSame(1, DB::table('messaging_inbox')->where('message_id', $messageId)->count());
        $this->assertSame(1, $this->metric('stockflow_checkout_sagas_total', ['outcome' => 'started']));
        $this->assertSame(1, $this->metric('stockflow_checkout_sagas_total', ['outcome' => 'completed']));
    }

    public function test_provider_saga_releases_confirmed_reservations_after_inventory_rejection(): void
    {
        $order = $this->orderWithItems(2);
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservations = $saga->reservations;

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservations[0]->reservation_id,
        ]);
        $this->outcome($saga, 'inventory.reservation.rejected.v1', [
            'reservation_id' => $reservations[1]->reservation_id,
            'reason' => 'INSUFFICIENT_STOCK',
        ]);

        $this->assertDatabaseHas('orders_checkout_sagas', [
            'id' => $saga->id,
            'status' => CheckoutSaga::STATUS_FAILED,
            'failure_reason' => 'INSUFFICIENT_STOCK',
        ]);
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order->id,
            'status' => Order::STATUS_RESERVATION_FAILED,
        ]);
        $this->assertDatabaseHas('orders_checkout_saga_reservations', [
            'reservation_id' => $reservations[0]->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_RELEASE_PENDING,
        ]);
        $this->assertDatabaseHas('orders_checkout_saga_reservations', [
            'reservation_id' => $reservations[1]->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_REJECTED,
        ]);

        $release = $this->assertProviderMessage('inventory.reservation.release.requested.v1');
        $this->assertSame($reservations[0]->reservation_id, $release->payload['reservation_id']);
        $this->providerMessage($release);

        $this->assertDatabaseHas('orders_reservation_status_projections', [
            'reservation_id' => $reservations[0]->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_RELEASE_PENDING,
            'last_routing_key' => 'inventory.reservation.release.requested.v1',
        ]);
    }

    public function test_checkout_read_model_exposes_projected_reservation_statuses(): void
    {
        $order = $this->orderWithItems();
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservation = $saga->reservations->firstOrFail();
        $request = $this->assertProviderMessage('inventory.reservation.requested.v1');

        $this->getJson('/api/orders/'.$order->id.'/checkout')
            ->assertOk()
            ->assertJsonCount(0, 'data.order.reservations');

        $this->providerMessage($request);

        $this->getJson('/api/orders/'.$order->id.'/checkout')
            ->assertOk()
            ->assertJsonPath('data.order.reservations.0.reservation_id', $reservation->reservation_id)
            ->assertJsonPath('data.order.reservations.0.status', CheckoutSagaReservation::STATUS_PENDING);

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservation->reservation_id,
        ]);

        $this->getJson('/api/orders/'.$order->id.'/checkout')
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('data.order.reservations.0.status', CheckoutSagaReservation::STATUS_CONFIRMED);

        $this->assertDatabaseHas('orders_reservation_status_projections', [
            'order_id' => $order->id,
            'order_item_id' => $reservation->order_item_id,
            'reservation_id' => $reservation->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_CONFIRMED,
            'last_routing_key' => 'inventory.reservation.confirmed.v1',
        ]);
    }

    public function test_reservation_projection_does_not_regress_on_late_request_message(): void
    {
        $order = $this->orderWithItems();
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservation = $saga->reservations->firstOrFail();
        $request = $this->assertProviderMessage('inventory.reservation.requested.v1');

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservation->reservation_id,
        ]);
        $this->providerMessage($request);

        $this->assertDatabaseHas('orders_reservation_status_projections', [
            'reservation_id' => $reservation->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_CONFIRMED,
            'last_routing_key' => 'inventory.reservation.confirmed.v1',
        ]);
    }

    public function test_provider_saga_compensates_payment_inventory_and_created_shipments_after_delivery_failure(): void
    {
        $order = $this->orderWithItems(shipmentCount: 2);
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservation = $saga->reservations->firstOrFail();

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservation->reservation_id,
        ]);
        $this->outcome($saga, 'payment.authorization.approved.v1', [
            'authorization_id' => 'auth_demo_001',
        ]);
        $this->outcome($saga, 'payment.capture.completed.v1', [
            'capture_id' => 'cap_demo_001',
        ]);

        $shipments = ProviderOutboxMessage::query()
            ->where('routing_key', 'delivery.shipment.requested.v1')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $shipments);

        $this->outcome($saga, 'delivery.shipment.created.v1', [
            'shipment_id' => $shipments[0]->payload['shipment_id'],
            'tracking_number' => 'TRK-DEMO-001',
        ]);
        $this->outcome($saga, 'delivery.shipment.creation_failed.v1', [
            'shipment_id' => $shipments[1]->payload['shipment_id'],
            'failure_code' => 'address_invalid',
        ]);

        $refund = $this->assertProviderMessage('payment.refund.requested.v1');
        $cancel = $this->assertProviderMessage('delivery.shipment.cancel_requested.v1');
        $release = $this->assertProviderMessage('inventory.reservation.release.requested.v1');

        $this->assertSame('pay_'.$order->id, $refund->payload['payment_id']);
        $this->assertSame($shipments[0]->payload['shipment_id'], $cancel->payload['shipment_id']);
        $this->assertSame($reservation->reservation_id, $release->payload['reservation_id']);

        $this->outcome($saga, 'payment.refund.completed.v1', [
            'refund_id' => 'ref_demo_001',
        ]);
        $this->outcome($saga, 'delivery.shipment.cancelled.v1', [
            'shipment_id' => $cancel->payload['shipment_id'],
        ]);
        $this->outcome($saga, 'inventory.reservation.released.v1', [
            'reservation_id' => $reservation->reservation_id,
        ]);

        $this->assertDatabaseHas('orders_checkout_sagas', [
            'id' => $saga->id,
            'status' => CheckoutSaga::STATUS_FAILED,
            'failure_reason' => 'address_invalid',
            'refund_id' => 'ref_demo_001',
            'refund_status' => CheckoutSaga::REFUND_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('orders_shipments', [
            'provider_shipment_id' => $cancel->payload['shipment_id'],
            'provider_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('orders_checkout_saga_reservations', [
            'reservation_id' => $reservation->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('orders_reservation_status_projections', [
            'reservation_id' => $reservation->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_RELEASED,
            'last_routing_key' => 'inventory.reservation.released.v1',
        ]);
        $this->assertSame(1, $this->metric('stockflow_checkout_sagas_total', ['outcome' => 'failed']));
        $this->assertSame(1, $this->metric('stockflow_checkout_saga_compensations_total', [
            'operation' => 'inventory_release',
            'outcome' => 'requested',
        ]));
        $this->assertSame(1, $this->metric('stockflow_checkout_saga_compensations_total', [
            'operation' => 'payment_refund',
            'outcome' => 'requested',
        ]));
        $this->assertSame(1, $this->metric('stockflow_checkout_saga_compensations_total', [
            'operation' => 'shipment_cancel',
            'outcome' => 'requested',
        ]));
    }

    public function test_provider_saga_records_inventory_release_failure(): void
    {
        $order = $this->orderWithItems(2);
        $saga = $this->app->make(CheckoutSagaService::class)->start($order->id);
        $reservations = $saga->reservations;

        $this->outcome($saga, 'inventory.reservation.confirmed.v1', [
            'reservation_id' => $reservations[0]->reservation_id,
        ]);
        $this->outcome($saga, 'inventory.reservation.rejected.v1', [
            'reservation_id' => $reservations[1]->reservation_id,
            'reason' => 'INSUFFICIENT_STOCK',
        ]);
        $this->outcome($saga, 'inventory.reservation.release_failed.v1', [
            'reservation_id' => $reservations[0]->reservation_id,
            'reason' => 'RESERVATION_NOT_ACTIVE',
        ]);

        $this->assertDatabaseHas('orders_checkout_sagas', [
            'id' => $saga->id,
            'compensation_failure_reason' => 'RESERVATION_NOT_ACTIVE',
        ]);
        $this->assertDatabaseHas('orders_checkout_saga_reservations', [
            'reservation_id' => $reservations[0]->reservation_id,
            'status' => CheckoutSagaReservation::STATUS_RELEASE_FAILED,
        ]);
        $this->assertSame(1, $this->metric('stockflow_checkout_saga_compensations_total', [
            'operation' => 'inventory_release',
            'outcome' => 'failed',
        ]));
    }

    private function orderWithItems(int $count = 1, int $shipmentCount = 1): Order
    {
        $now = now();
        $categoryId = DB::table('catalog_categories')->insertGetId([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $cartId = DB::table('orders_carts')->insertGetId([
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Order $order */
        $order = Order::query()->create([
            'cart_id' => $cartId,
            'status' => Order::STATUS_RESERVATION_PENDING,
            'total_amount_minor' => 129900 * $count,
            'currency' => 'USD',
            'recipient_name' => 'Jane Doe',
            'recipient_phone' => '+79990001122',
            'delivery_country_code' => 'RU',
            'delivery_city' => 'Moscow',
            'delivery_postal_code' => '101000',
            'delivery_address_line_1' => 'Red Square 1',
        ]);

        for ($index = 1; $index <= $count; $index++) {
            $productId = DB::table('catalog_products')->insertGetId([
                'category_id' => $categoryId,
                'name' => 'Scanner '.$index,
                'slug' => 'scanner-'.$index,
                'sku' => 'SCAN-00'.$index,
                'status' => 'published',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order->items()->create([
                'product_id' => $productId,
                'sku' => 'SCAN-00'.$index,
                'product_name' => 'Scanner '.$index,
                'quantity' => 1,
                'unit_amount_minor' => 129900,
                'currency' => 'USD',
                'line_amount_minor' => 129900,
            ]);
        }

        for ($index = 1; $index <= $shipmentCount; $index++) {
            $order->shipments()->create(['delivery_service' => 'stockflow-express']);
        }

        return $order->load('items', 'shipments');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outcome(CheckoutSaga $saga, string $routingKey, array $payload, ?string $messageId = null): void
    {
        $this->app->make(ProviderOutcomeProcessor::class)->process($routingKey, [
            'message_id' => $messageId ?? (string) Str::uuid(),
            'correlation_id' => $saga->correlation_id,
        ], $payload);
    }

    private function providerMessage(ProviderOutboxMessage $message): void
    {
        $this->app->make(ProviderOutcomeProcessor::class)->process(
            $message->routing_key,
            $message->headers,
            $message->payload,
        );
    }

    private function assertProviderMessage(string $routingKey): ProviderOutboxMessage
    {
        /** @var ProviderOutboxMessage $message */
        $message = ProviderOutboxMessage::query()
            ->where('routing_key', $routingKey)
            ->latest('id')
            ->firstOrFail();

        return $message;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function metric(string $name, array $labels): int
    {
        return $this->app->make(MetricsCollector::class)->value($name, $labels);
    }
}
