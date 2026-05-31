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
use App\Domains\Pricing\Models\Promotion;
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
            ->assertJsonPath('data.subtotal_amount_minor', 259800)
            ->assertJsonPath('data.discount_amount_minor', 0)
            ->assertJsonPath('data.total_amount_minor', 259800)
            ->assertJsonPath('data.items.0.price_type', 'retail')
            ->assertJsonPath('data.items.0.price_version', 1)
            ->assertJsonPath('data.items.0.unit_amount_minor', 129900)
            ->json('data');

        ProductPrice::query()->where('product_id', $product->id)->update([
            'amount_minor' => 9900,
        ]);

        $this->postJson('/api/orders/'.$order['id'].'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_RESERVATION_PENDING)
            ->assertJsonPath('data.total_amount_minor', 259800)
            ->assertJsonPath('data.items.0.price_version', 1)
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

    public function test_draft_order_snapshots_city_price_version_and_ignores_future_activation(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        $this->createPrice($product, 129900);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'city_code' => ' WAW ',
            'price_version' => 2,
            'amount_minor' => 119900,
            'currency' => 'USD',
            'is_active' => true,
            'active_from' => now()->subHour(),
        ]);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'city_code' => ' WAW ',
            'price_version' => 3,
            'amount_minor' => 9900,
            'currency' => 'USD',
            'is_active' => true,
            'active_from' => now()->addHour(),
        ]);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->json('data');

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
            'city_code' => 'waw',
        ])
            ->assertCreated()
            ->assertJsonPath('data.city_code', 'waw')
            ->assertJsonPath('data.total_amount_minor', 119900)
            ->assertJsonPath('data.items.0.price_city_code', 'waw')
            ->assertJsonPath('data.items.0.price_version', 2)
            ->assertJsonPath('data.items.0.unit_amount_minor', 119900);
    }

    public function test_draft_order_applies_active_promotion_and_snapshots_discount(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $this->createPrice($product, 129900);

        Promotion::query()->create([
            'code' => ' save10 ',
            'discount_type' => Promotion::TYPE_PERCENT,
            'discount_value' => 10,
            'currency' => 'USD',
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->json('data');

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
            'promo_code' => 'save10',
        ])
            ->assertCreated()
            ->assertJsonPath('data.promo_code', 'SAVE10')
            ->assertJsonPath('data.subtotal_amount_minor', 259800)
            ->assertJsonPath('data.discount_amount_minor', 25980)
            ->assertJsonPath('data.total_amount_minor', 233820);
    }

    public function test_draft_order_rejects_inactive_promotion(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $this->createPrice($product, 129900);

        Promotion::query()->create([
            'code' => 'LATER',
            'discount_type' => Promotion::TYPE_FIXED_AMOUNT,
            'discount_value' => 1000,
            'currency' => 'USD',
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->json('data');

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
            'promo_code' => 'later',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Promotion LATER is not active.');
    }

    public function test_checkout_configures_address_payment_and_separate_shipments(): void
    {
        $scanner = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $terminal = $this->createProduct('Mobile Terminal', 'mobile-terminal', 'TERM-001');
        $this->createPrice($scanner, 129900);
        $this->createPrice($terminal, 249900);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $scanner->id,
            'quantity' => 2,
        ])->json('data');

        $cart = $this->postJson('/api/cart/items', [
            'cart_id' => $cart['id'],
            'product_id' => $terminal->id,
            'quantity' => 1,
        ])->json('data');

        $order = $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])->json('data');

        $optionsPayload = $this->getJson('/api/checkout/options')
            ->assertOk()
            ->assertJsonPath('data.payment_methods.0.code', 'bank_card')
            ->assertJsonPath('data.delivery_services.1.code', 'cdek')
            ->json();

        $checkoutPayload = $this->putJson('/api/orders/'.$order['id'].'/checkout', [
            'payment_method' => 'sbp',
            'address' => [
                'recipient_name' => 'Alexandra Shornikova',
                'recipient_phone' => '+79990000000',
                'country_code' => 'ru',
                'city' => 'Москва',
                'postal_code' => '101000',
                'address_line_1' => 'ул. Тверская, д. 1',
                'address_line_2' => 'кв. 2',
            ],
            'shipments' => [
                [
                    'delivery_service' => 'stockflow_courier',
                    'items' => [
                        ['order_item_id' => $order['items'][0]['id'], 'quantity' => 2],
                    ],
                ],
                [
                    'delivery_service' => 'cdek',
                    'items' => [
                        ['order_item_id' => $order['items'][1]['id'], 'quantity' => 1],
                    ],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.order.payment_method', 'sbp')
            ->assertJsonPath('data.order.address.country_code', 'RU')
            ->assertJsonCount(2, 'data.order.shipments')
            ->assertJsonPath('data.order.shipments.1.delivery_service', 'cdek')
            ->json();

        $this->assertDatabaseHas('orders_orders', [
            'id' => $order['id'],
            'payment_method' => 'sbp',
            'delivery_city' => 'Москва',
        ]);
        $this->assertDatabaseCount('orders_shipments', 2);
        $this->assertDatabaseCount('orders_shipment_items', 2);

        foreach ($this->orderContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/checkout/options', '200', 'CheckoutOptionsResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/checkout', '200', 'CheckoutResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/checkout', '409', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'CheckoutOptionsResponse', $optionsPayload);
            $this->assertSchemaMatchesPayload($contract, 'CheckoutResponse', $checkoutPayload);
        }
    }

    public function test_checkout_rejects_incomplete_shipment_distribution(): void
    {
        $scanner = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $terminal = $this->createProduct('Mobile Terminal', 'mobile-terminal', 'TERM-001');
        $this->createPrice($scanner, 129900);
        $this->createPrice($terminal, 249900);

        $cart = $this->postJson('/api/cart/items', [
            'product_id' => $scanner->id,
            'quantity' => 1,
        ])->json('data');
        $cart = $this->postJson('/api/cart/items', [
            'cart_id' => $cart['id'],
            'product_id' => $terminal->id,
            'quantity' => 1,
        ])->json('data');
        $order = $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
        ])->json('data');

        $this->putJson('/api/orders/'.$order['id'].'/checkout', [
            'payment_method' => 'bank_card',
            'address' => [
                'recipient_name' => 'Alexandra Shornikova',
                'recipient_phone' => '+79990000000',
                'country_code' => 'RU',
                'city' => 'Москва',
                'postal_code' => '101000',
                'address_line_1' => 'ул. Тверская, д. 1',
            ],
            'shipments' => [
                [
                    'delivery_service' => 'cdek',
                    'items' => [
                        ['order_item_id' => $order['items'][0]['id'], 'quantity' => 1],
                    ],
                ],
            ],
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Shipment items must match order items.');
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
