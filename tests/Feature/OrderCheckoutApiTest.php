<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Orders\Events\InventoryReservationFailed;
use App\Domains\Orders\Events\InventoryReserved;
use App\Domains\Orders\Events\InventoryReserveRequested;
use App\Domains\Orders\Events\OrderCreated;
use App\Domains\Orders\Models\Order;
use App\Domains\Pricing\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_cart_draft_and_confirm_reserve_stock_with_price_snapshot(): void
    {
        Event::fake([
            InventoryReserveRequested::class,
            InventoryReserved::class,
            OrderCreated::class,
        ]);

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
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('data.total_amount_minor', 259800)
            ->assertJsonPath('data.items.0.unit_amount_minor', 129900);

        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(2, $stockItem->reserved_quantity);
        $this->assertSame(8, $stockItem->availableQuantity());

        Event::assertDispatched(InventoryReserveRequested::class, fn (InventoryReserveRequested $event): bool => $event->payload()['event'] === InventoryReserveRequested::NAME);
        Event::assertDispatched(InventoryReserved::class, fn (InventoryReserved $event): bool => $event->payload()['event'] === InventoryReserved::NAME);
        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event): bool => $event->payload()['event'] === OrderCreated::NAME);
    }

    public function test_confirm_marks_order_failed_when_stock_is_not_available(): void
    {
        Event::fake([
            InventoryReservationFailed::class,
            InventoryReserveRequested::class,
        ]);

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
            ->assertStatus(409)
            ->assertJsonPath('message', 'Insufficient available stock for SCAN-001: requested 2, available 1.');

        $stockItem->refresh();

        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertDatabaseHas('orders_orders', [
            'id' => $order['id'],
            'status' => Order::STATUS_RESERVATION_FAILED,
        ]);

        Event::assertDispatched(InventoryReserveRequested::class);
        Event::assertDispatched(InventoryReservationFailed::class, function (InventoryReservationFailed $event): bool {
            return $event->payload()['event'] === InventoryReservationFailed::NAME
                && str_contains($event->payload()['reason'], 'Insufficient available stock');
        });
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
            $this->assertContractDeclaresResponse($contract, '/api/orders/draft', '201', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/confirm', '200', 'OrderResponse');
            $this->assertContractDeclaresResponse($contract, '/api/orders/{id}/confirm', '409', 'ErrorResponse');
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
}
