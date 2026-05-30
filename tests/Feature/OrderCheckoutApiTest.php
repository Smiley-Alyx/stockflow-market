<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Events\OrderCreated;
use App\Domains\Orders\Events\OrderExpired;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Events\OrderReservationFailed;
use App\Domains\Orders\Events\OrderReservationSucceeded;
use App\Domains\Orders\Models\Order;
use App\Domains\Pricing\Models\ProductPrice;
use App\Infrastructure\Messaging\DomainEventPublisher;
use App\Infrastructure\Messaging\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class OrderCheckoutApiTest extends TestCase
{
    use AssertsOpenApiContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_cart_draft_and_confirm_requests_async_stock_reservation_with_price_snapshot(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $stockItem = $this->createStockItem($product, 10);
        $this->createPrice($product, 129900);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'wholesale',
            'amount_minor' => 99900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->json('data');

        $order = $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Order::STATUS_DRAFT)
            ->assertJsonPath('data.total_amount_minor', 259800)
            ->assertJsonPath('data.items.0.unit_amount_minor', 129900)
            ->json('data');

        ProductPrice::query()->where('product_id', $product->id)->update([
            'amount_minor' => 9900,
        ]);

        $this->postJson('/api/orders/'.$order['id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_RESERVATION_PENDING)
            ->assertJsonPath('data.total_amount_minor', 259800)
            ->assertJsonPath('data.items.0.unit_amount_minor', 129900);

        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(10, $stockItem->availableQuantity());

        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => OrderConfirmationRequested::NAME,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order['id'],
        ]);

        $this->app->make(DomainEventPublisher::class)->publishPending();

        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(2, $stockItem->reserved_quantity);
        $this->assertSame(8, $stockItem->availableQuantity());

        $this->assertDatabaseHas('orders_orders', [
            'id' => $order['id'],
            'status' => Order::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => OrderReservationSucceeded::NAME,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order['id'],
        ]);
        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => OrderCreated::NAME,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order['id'],
        ]);
    }

    public function test_async_reservation_marks_order_failed_when_stock_is_not_available(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $stockItem = $this->createStockItem($product, 1);
        $this->createPrice($product, 129900);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->json('data');

        $order = $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])->json('data');

        $this->postJson('/api/orders/'.$order['id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_RESERVATION_PENDING);

        $this->assertDatabaseHas('orders_orders', [
            'id' => $order['id'],
            'status' => Order::STATUS_RESERVATION_PENDING,
        ]);

        $this->app->make(DomainEventPublisher::class)->publishPending();

        $stockItem->refresh();

        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order['id'],
            'status' => Order::STATUS_RESERVATION_FAILED,
        ]);

        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => OrderConfirmationRequested::NAME,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order['id'],
        ]);

        /** @var OutboxMessage $failedEvent */
        $failedEvent = OutboxMessage::query()
            ->where('event_name', OrderReservationFailed::NAME)
            ->firstOrFail();

        $this->assertSame((string) $order['id'], $failedEvent->aggregate_id);
        $this->assertStringContainsString('Insufficient available stock', $failedEvent->payload['reason']);
    }

    public function test_paid_order_deducts_reserved_stock_idempotently(): void
    {
        [$order, $stockItem] = $this->confirmedOrder(quantity: 2, onHand: 10);

        $this->postJson('/api/orders/'.$order['id'].'/paid')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PAID);

        $this->postJson('/api/orders/'.$order['id'].'/paid')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PAID);

        $stockItem->refresh();

        $this->assertSame(8, $stockItem->on_hand_quantity);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::TYPE_DEDUCTED)->count());
        $this->assertSame(1, Reservation::query()
            ->where('idempotency_key', 'like', 'order:'.$order['id'].':item:%')
            ->where('status', Reservation::STATUS_CONSUMED)
            ->count());
        $this->assertSame(1, OutboxMessage::query()->where('event_name', OrderPaid::NAME)->count());
    }

    public function test_cancelled_order_releases_reserved_stock_idempotently(): void
    {
        [$order, $stockItem] = $this->confirmedOrder(quantity: 2, onHand: 10);

        $this->postJson('/api/orders/'.$order['id'].'/cancelled')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);

        $this->postJson('/api/orders/'.$order['id'].'/cancelled')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);

        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::TYPE_RELEASED)->count());
        $this->assertSame(1, Reservation::query()
            ->where('idempotency_key', 'like', 'order:'.$order['id'].':item:%')
            ->where('status', Reservation::STATUS_CANCELED)
            ->count());
        $this->assertSame(1, OutboxMessage::query()->where('event_name', OrderCancelled::NAME)->count());
    }

    public function test_expired_order_releases_reserved_stock_idempotently(): void
    {
        [$order, $stockItem] = $this->confirmedOrder(quantity: 2, onHand: 10);

        $this->postJson('/api/orders/'.$order['id'].'/expired')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_EXPIRED);

        $this->postJson('/api/orders/'.$order['id'].'/expired')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_EXPIRED);

        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::TYPE_EXPIRED)->count());
        $this->assertSame(1, Reservation::query()
            ->where('idempotency_key', 'like', 'order:'.$order['id'].':item:%')
            ->where('status', Reservation::STATUS_EXPIRED)
            ->count());
        $this->assertSame(1, OutboxMessage::query()->where('event_name', OrderExpired::NAME)->count());
    }

    public function test_draft_endpoint_rejects_cart_without_active_price(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->json('data');

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Active price not found for product '.$product->id.'.');
    }

    public function test_order_endpoints_match_gateway_and_orders_contracts(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $this->createStockItem($product, 5);
        $this->createPrice($product, 129900);

        $cartPayload = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
            ->assertCreated()
            ->assertHeader('content-type', 'application/json')
            ->json();

        $draftPayload = $this->postJson('/api/orders/draft', [
            'cart_id' => $cartPayload['data']['id'],
        ])
            ->assertCreated()
            ->assertHeader('content-type', 'application/json')
            ->json();

        $confirmedPayload = $this->postJson('/api/orders/'.$draftPayload['data']['id'].'/confirm')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->orderContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/cart/items', '201', 'CartResponse');
            $this->assertContractDeclaresResponse($contract, '/api/cart/items', '429', 'ErrorResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/draft', '201', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/draft', '429', 'ErrorResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/confirm', '200', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/confirm', '409', 'ErrorResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/confirm', '429', 'ErrorResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/paid', '200', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/cancelled', '200', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/expired', '200', 'OrderResponse');
            $this->assertSchemaMatchesPayload($contract, 'CartResponse', $cartPayload);
            $this->assertSchemaMatchesPayload($contract, 'Cart', $cartPayload['data']);
            $this->assertSchemaMatchesPayload($contract, 'CartItem', $cartPayload['data']['items'][0]);
            $this->assertSchemaMatchesPayload($contract, 'OrderResponse', $confirmedPayload);
            $this->assertSchemaMatchesPayload($contract, 'Order', $confirmedPayload['data']);
            $this->assertSchemaMatchesPayload($contract, 'OrderItem', $confirmedPayload['data']['items'][0]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function orderContracts(): array
    {
        return [
            'gateway' => base_path('services/gateway/contracts/openapi.yaml'),
            'orders' => base_path('services/orders/contracts/openapi.yaml'),
        ];
    }

    private function createProduct(string $name, string $slug, string $sku): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'devices'],
            [
                'name' => 'Devices',
                'is_active' => true,
            ],
        );

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'description' => 'Compact device for warehouse teams.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function createStockItem(Product $product, int $onHand): StockItem
    {
        return StockItem::query()->create([
            'warehouse_id' => Warehouse::query()->create([
                'code' => 'WAW',
                'name' => 'WAW Warehouse',
                'city_code' => 'waw',
                'city_name' => 'Warsaw',
                'is_active' => true,
            ])->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => 0,
        ]);
    }

    private function createPrice(Product $product, int $amountMinor): ProductPrice
    {
        return ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: StockItem}
     */
    private function confirmedOrder(int $quantity, int $onHand): array
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $stockItem = $this->createStockItem($product, $onHand);
        $this->createPrice($product, 129900);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => $quantity,
        ])->json('data');

        $order = $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])->json('data');

        $this->postJson('/api/orders/'.$order['id'].'/confirm')->assertOk();
        $this->app->make(DomainEventPublisher::class)->publishPending();

        return [$order, $stockItem->fresh()];
    }
}
