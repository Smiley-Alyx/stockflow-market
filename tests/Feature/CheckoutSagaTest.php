<?php

namespace Tests\Feature;

use App\Domains\Orders\Models\CheckoutSaga;
use App\Domains\Orders\Models\CheckoutSagaReservation;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\CheckoutSagaService;
use App\Infrastructure\Messaging\ProviderOutboxMessage;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeProcessor;
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
        $authorizationMessage = $this->assertProviderMessage('payment.authorization.requested.v1');
        $this->assertSame('v1', $authorizationMessage->headers['schema_version']);

        $this->outcome($saga, 'payment.authorization.approved.v1', [
            'authorization_id' => 'auth_demo_001',
        ]);
        $this->assertProviderMessage('payment.capture.requested.v1');
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CONFIRMED,
        ]);

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

        $this->assertDatabaseHas('orders_checkout_sagas', [
            'id' => $saga->id,
            'status' => CheckoutSaga::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('orders_shipments', [
            'order_id' => $order->id,
            'provider_status' => 'created',
            'tracking_number' => 'TRK-DEMO-001',
        ]);
        $this->assertSame(1, DB::table('messaging_inbox')->where('message_id', $messageId)->count());
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

        $release = $this->assertProviderMessage('inventory.reservation.release.requested.v1');
        $this->assertSame($reservations[0]->reservation_id, $release->payload['reservation_id']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['stockflow.provider_saga.enabled' => true]);
    }

    private function orderWithItems(int $count = 1): Order
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

        $order->shipments()->create(['delivery_service' => 'stockflow-express']);

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

    private function assertProviderMessage(string $routingKey): ProviderOutboxMessage
    {
        /** @var ProviderOutboxMessage $message */
        $message = ProviderOutboxMessage::query()
            ->where('routing_key', $routingKey)
            ->latest('id')
            ->firstOrFail();

        return $message;
    }
}
