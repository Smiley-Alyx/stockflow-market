<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\IdempotencyConflict;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use App\Infrastructure\Messaging\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class InventoryStockApiTest extends TestCase
{
    use AssertsOpenApiContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_stock_endpoint_returns_aggregated_stock_by_sku(): void
    {
        $product = $this->createProduct();
        $primary = $this->createWarehouse('WAW');
        $overflow = $this->createWarehouse('KRK');

        StockItem::query()->create([
            'warehouse_id' => $primary->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 10,
            'reserved_quantity' => 3,
        ]);

        StockItem::query()->create([
            'warehouse_id' => $overflow->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 5,
            'reserved_quantity' => 1,
        ]);

        $this->getJson('/api/inventory/stock?sku=SCAN-001')
            ->assertOk()
            ->assertJsonPath('data.sku', 'SCAN-001')
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.on_hand_quantity', 15)
            ->assertJsonPath('data.reserved_quantity', 4)
            ->assertJsonPath('data.available_quantity', 11)
            ->assertJsonPath('data.warehouses.0.warehouse_code', 'WAW')
            ->assertJsonPath('data.warehouses.0.city_code', 'waw')
            ->assertJsonPath('data.warehouses.0.available_quantity', 7)
            ->assertJsonPath('data.warehouses.1.warehouse_code', 'KRK')
            ->assertJsonPath('data.warehouses.1.available_quantity', 4);
    }

    public function test_stock_endpoint_filters_warehouses_by_city(): void
    {
        $product = $this->createProduct();
        $warsaw = $this->createWarehouse('WAW', 'waw', 'Warsaw');
        $krakow = $this->createWarehouse('KRK', 'krk', 'Krakow');

        StockItem::query()->create([
            'warehouse_id' => $warsaw->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 10,
            'reserved_quantity' => 2,
        ]);

        StockItem::query()->create([
            'warehouse_id' => $krakow->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 20,
            'reserved_quantity' => 5,
        ]);

        $this->getJson('/api/inventory/stock?sku=SCAN-001&city_code=krk')
            ->assertOk()
            ->assertJsonPath('data.on_hand_quantity', 20)
            ->assertJsonPath('data.available_quantity', 15)
            ->assertJsonCount(1, 'data.warehouses')
            ->assertJsonPath('data.warehouses.0.warehouse_code', 'KRK')
            ->assertJsonPath('data.warehouses.0.city_name', 'Krakow');
    }

    public function test_stock_endpoint_returns_aggregated_stock_by_product_id(): void
    {
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse('WAW');

        StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 8,
            'reserved_quantity' => 2,
        ]);

        $this->getJson('/api/inventory/stock?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('data.sku', 'SCAN-001')
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.available_quantity', 6);
    }

    public function test_stock_endpoint_matches_gateway_and_inventory_contracts(): void
    {
        $product = $this->createProduct();
        $warehouse = $this->createWarehouse('WAW');

        StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 10,
            'reserved_quantity' => 3,
        ]);

        $payload = $this->getJson('/api/inventory/stock?sku=SCAN-001')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->inventoryContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/inventory/stock', '200', 'StockResponse');
            $this->assertContractDeclaresResponse($contract, '/api/inventory/stock', '404', 'ErrorResponse');
            $this->assertContractDeclaresResponse($contract, '/api/inventory/stock', '422', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'StockResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'Stock', $payload['data']);
            $this->assertSchemaMatchesPayload($contract, 'WarehouseStock', $payload['data']['warehouses'][0]);
        }
    }

    public function test_stock_movements_endpoint_uses_cursor_pagination(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);

        $first = $this->createMovement($stockItem, StockMovement::TYPE_RECEIVED, now()->subMinutes(1));
        $second = $this->createMovement($stockItem, StockMovement::TYPE_RESERVED, now()->subMinutes(2));
        $third = $this->createMovement($stockItem, StockMovement::TYPE_DEDUCTED, now()->subMinutes(3));

        $page = $this->getJson('/api/inventory/stock-movements?stock_item_id='.$stockItem->id.'&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('meta.per_page', 2)
            ->json();

        $this->assertNotNull($page['meta']['next_cursor']);

        $this->getJson('/api/inventory/stock-movements?stock_item_id='.$stockItem->id.'&per_page=2&cursor='.urlencode($page['meta']['next_cursor']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_stock_movements_endpoint_rejects_invalid_cursor(): void
    {
        $this->getJson('/api/inventory/stock-movements?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid movement cursor.');
    }

    public function test_stock_movements_endpoint_matches_gateway_and_inventory_contracts(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $this->createMovement($stockItem, StockMovement::TYPE_RECEIVED, now());

        $payload = $this->getJson('/api/inventory/stock-movements?stock_item_id='.$stockItem->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->inventoryContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/inventory/stock-movements', '200', 'StockMovementPage');
            $this->assertContractDeclaresResponse($contract, '/api/inventory/stock-movements', '422', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'StockMovementPage', $payload);
            $this->assertSchemaMatchesPayload($contract, 'StockMovement', $payload['data'][0]);
        }
    }

    public function test_stock_endpoint_requires_sku_or_product_id(): void
    {
        $this->getJson('/api/inventory/stock')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'product_id']);
    }

    public function test_inventory_service_reserves_idempotently_deducts_and_returns_stock(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $reservation = $inventory->reserve($stockItem, 4, 'order-ORD-1', now()->addMinutes(10), 'order', 'ORD-1');
        $repeated = $inventory->reserve($stockItem, 4, 'order-ORD-1', $reservation->reservation_expires_at, 'order', 'ORD-1');
        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(4, $stockItem->reserved_quantity);
        $this->assertSame(6, $stockItem->availableQuantity());
        $this->assertTrue($reservation->is($repeated));
        $this->assertSame(Reservation::STATUS_ACTIVE, $reservation->status);
        $this->assertSame(1, $stockItem->reservations()->count());

        $deduction = $inventory->deduct($stockItem, 3, 'order', 'ORD-1');
        $stockItem->refresh();

        $this->assertSame(7, $stockItem->on_hand_quantity);
        $this->assertSame(1, $stockItem->reserved_quantity);
        $this->assertSame(6, $stockItem->availableQuantity());
        $this->assertSame(StockMovement::TYPE_DEDUCTED, $deduction->type);

        $return = $inventory->returnStock($stockItem, 2, 'return', 'RET-1');
        $stockItem->refresh();

        $this->assertSame(9, $stockItem->on_hand_quantity);
        $this->assertSame(1, $stockItem->reserved_quantity);
        $this->assertSame(8, $stockItem->availableQuantity());
        $this->assertSame(StockMovement::TYPE_RETURNED, $return->type);
        $this->assertSame(3, $stockItem->movements()->count());

        $this->assertSame(3, OutboxMessage::query()->where('event_name', StockChanged::NAME)->count());

        /** @var OutboxMessage $stockChanged */
        $stockChanged = OutboxMessage::query()
            ->where('event_name', StockChanged::NAME)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('SCAN-001', $stockChanged->payload['stock']['sku']);
        $this->assertSame(StockMovement::TYPE_RETURNED, $stockChanged->payload['movement']['type']);
    }

    public function test_inventory_service_rejects_reservation_over_available_stock(): void
    {
        $stockItem = $this->createStockItem(onHand: 2);

        $this->expectException(InsufficientStock::class);

        $this->app->make(InventoryService::class)->reserve($stockItem, 3, 'order-ORD-1', now()->addMinutes(10));
    }

    public function test_reservation_idempotency_key_cannot_change_request_shape(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $inventory->reserve($stockItem, 2, 'order-ORD-1', now()->addMinutes(10));

        $this->expectException(IdempotencyConflict::class);

        $inventory->reserve($stockItem, 3, 'order-ORD-1', now()->addMinutes(10));
    }

    public function test_reservation_can_be_canceled_idempotently(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $inventory->reserve($stockItem, 4, 'order-ORD-1', now()->addMinutes(10));

        $canceled = $inventory->cancelReservation('order-ORD-1');
        $repeated = $inventory->cancelReservation('order-ORD-1');
        $stockItem->refresh();

        $this->assertTrue($canceled->is($repeated));
        $this->assertSame(Reservation::STATUS_CANCELED, $repeated->status);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertSame(2, $stockItem->movements()->count());
    }

    public function test_expired_reservation_releases_stock(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $inventory->reserve($stockItem, 4, 'order-ORD-1', now()->addSecond());
        $expired = $inventory->expireReservations(now()->addMinutes(2));
        $stockItem->refresh();

        $this->assertSame(1, $expired);
        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertDatabaseHas('inventory_reservations', [
            'idempotency_key' => 'order-ORD-1',
            'status' => Reservation::STATUS_EXPIRED,
        ]);
    }

    public function test_expire_reservations_command_releases_stock_and_logs_metric(): void
    {
        Log::spy();

        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $inventory->reserve($stockItem, 4, 'order-ORD-1', now()->addSecond());
        $this->travel(2)->seconds();

        $this->artisan('inventory:reservations:expire')
            ->expectsOutput('Expired 1 inventory reservation(s).')
            ->assertExitCode(0);

        $stockItem->refresh();

        $this->assertSame(0, $stockItem->reserved_quantity);
        $this->assertDatabaseHas('inventory_reservations', [
            'idempotency_key' => 'order-ORD-1',
            'status' => Reservation::STATUS_EXPIRED,
        ]);
        Log::shouldHaveReceived('info')
            ->once()
            ->with('inventory.reservations.expired', ['expired_count' => 1]);
    }

    public function test_expire_reservations_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('inventory:reservations:expire')
            ->assertExitCode(0);
    }

    public function test_stock_movement_archive_command_moves_old_movements(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $old = $this->createMovement($stockItem, StockMovement::TYPE_RECEIVED, now()->subDays(200));
        $recent = $this->createMovement($stockItem, StockMovement::TYPE_RESERVED, now()->subDays(5));

        $this->artisan('inventory:stock-movements:archive --days=180 --batch-size=1')
            ->expectsOutput('Archived 1 inventory stock movement(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_stock_movements', ['id' => $old->id]);
        $this->assertDatabaseHas('inventory_stock_movements', ['id' => $recent->id]);
        $this->assertDatabaseHas('inventory_stock_movement_archives', [
            'original_id' => $old->id,
            'stock_item_id' => $stockItem->id,
            'type' => StockMovement::TYPE_RECEIVED,
        ]);
    }

    public function test_stock_movement_archive_command_can_dry_run(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $old = $this->createMovement($stockItem, StockMovement::TYPE_RECEIVED, now()->subDays(200));

        $this->artisan('inventory:stock-movements:archive --days=180 --dry-run')
            ->expectsOutputToContain('Dry run: 1 inventory stock movement(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('inventory_stock_movements', ['id' => $old->id]);
        $this->assertSame(0, DB::table('inventory_stock_movement_archives')->count());
    }

    public function test_stock_movement_archive_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('inventory:stock-movements:archive')
            ->assertExitCode(0);
    }

    public function test_reservation_api_requires_idempotency_and_reuses_successful_response(): void
    {
        $stockItem = $this->createStockItem(onHand: 10);
        $expiresAt = now()->addMinutes(10)->toJSON();

        $this->postJson('/api/inventory/reservations', [
            'stock_item_id' => $stockItem->id,
            'quantity' => 2,
            'reservation_expires_at' => $expiresAt,
        ])->assertUnprocessable();

        $first = $this->withHeader('Idempotency-Key', 'api-reserve-1')
            ->postJson('/api/inventory/reservations', [
                'stock_item_id' => $stockItem->id,
                'quantity' => 2,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE)
            ->json('data');

        $second = $this->withHeader('Idempotency-Key', 'api-reserve-1')
            ->postJson('/api/inventory/reservations', [
                'stock_item_id' => $stockItem->id,
                'quantity' => 2,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, $stockItem->fresh()->reserved_quantity);
    }

    public function test_reservation_api_selects_available_warehouse_from_city(): void
    {
        $product = $this->createProduct();
        $warsaw = $this->createWarehouse('WAW', 'waw', 'Warsaw');
        $krakow = $this->createWarehouse('KRK', 'krk', 'Krakow');
        $expiresAt = now()->addMinutes(10)->toJSON();

        StockItem::query()->create([
            'warehouse_id' => $warsaw->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        $krakowStock = StockItem::query()->create([
            'warehouse_id' => $krakow->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $this->withHeader('Idempotency-Key', 'api-reserve-krk')
            ->postJson('/api/inventory/reservations', [
                'product_id' => $product->id,
                'city_code' => 'krk',
                'quantity' => 3,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->assertJsonPath('data.stock_item_id', $krakowStock->id)
            ->assertJsonPath('data.warehouse_code', 'KRK')
            ->assertJsonPath('data.city_code', 'krk');

        $this->assertSame(3, $krakowStock->fresh()->reserved_quantity);
    }

    public function test_reservation_api_falls_back_to_another_city_when_nearest_lacks_stock(): void
    {
        $product = $this->createProduct();
        $warsaw = $this->createWarehouse('WAW', 'waw', 'Warsaw');
        $krakow = $this->createWarehouse('KRK', 'krk', 'Krakow');
        $expiresAt = now()->addMinutes(10)->toJSON();

        StockItem::query()->create([
            'warehouse_id' => $warsaw->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        $fallbackStock = StockItem::query()->create([
            'warehouse_id' => $krakow->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $this->withHeader('Idempotency-Key', 'api-reserve-fallback')
            ->postJson('/api/inventory/reservations', [
                'product_id' => $product->id,
                'city_code' => 'waw',
                'routing_strategy' => 'fallback',
                'quantity' => 3,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->assertJsonPath('data.stock_item_id', $fallbackStock->id)
            ->assertJsonPath('data.routing_strategy', 'fallback')
            ->assertJsonPath('data.shipments.0.warehouse_code', 'KRK');

        $this->assertSame(3, $fallbackStock->fresh()->reserved_quantity);
    }

    public function test_reservation_api_splits_shipments_across_warehouses(): void
    {
        $product = $this->createProduct();
        $warsaw = $this->createWarehouse('WAW', 'waw', 'Warsaw');
        $krakow = $this->createWarehouse('KRK', 'krk', 'Krakow');
        $expiresAt = now()->addMinutes(10)->toJSON();

        $nearestStock = StockItem::query()->create([
            'warehouse_id' => $warsaw->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 2,
            'reserved_quantity' => 0,
        ]);

        $fallbackStock = StockItem::query()->create([
            'warehouse_id' => $krakow->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $payload = $this->withHeader('Idempotency-Key', 'api-reserve-split')
            ->postJson('/api/inventory/reservations', [
                'product_id' => $product->id,
                'city_code' => 'waw',
                'routing_strategy' => 'split_shipment',
                'quantity' => 5,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->assertJsonPath('data.routing_strategy', 'split_shipment')
            ->assertJsonCount(2, 'data.shipments')
            ->json('data');

        $this->assertSame([2, 3], array_column($payload['shipments'], 'quantity'));
        $this->assertSame(['WAW', 'KRK'], array_column($payload['shipments'], 'warehouse_code'));
        $this->assertSame(2, $nearestStock->fresh()->reserved_quantity);
        $this->assertSame(3, $fallbackStock->fresh()->reserved_quantity);

        $repeat = $this->withHeader('Idempotency-Key', 'api-reserve-split')
            ->postJson('/api/inventory/reservations', [
                'product_id' => $product->id,
                'city_code' => 'waw',
                'routing_strategy' => 'split_shipment',
                'quantity' => 5,
                'reservation_expires_at' => $expiresAt,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(array_column($payload['shipments'], 'id'), array_column($repeat['shipments'], 'id'));
        $this->assertSame(2, $nearestStock->fresh()->reserved_quantity);
        $this->assertSame(3, $fallbackStock->fresh()->reserved_quantity);
    }

    /**
     * @return array<string, string>
     */
    private function inventoryContracts(): array
    {
        return [
            'gateway' => base_path('services/gateway/contracts/openapi.yaml'),
            'inventory' => base_path('services/inventory/contracts/openapi.yaml'),
        ];
    }

    private function createStockItem(int $onHand): StockItem
    {
        return StockItem::query()->create([
            'warehouse_id' => $this->createWarehouse('WAW')->id,
            'product_id' => $this->createProduct()->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => 0,
        ]);
    }

    private function createMovement(StockItem $stockItem, string $type, mixed $occurredAt): StockMovement
    {
        return StockMovement::query()->create([
            'stock_item_id' => $stockItem->id,
            'type' => $type,
            'quantity' => 1,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function createWarehouse(string $code, ?string $cityCode = null, ?string $cityName = null): Warehouse
    {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code.' Warehouse',
            'city_code' => $cityCode ?? strtolower($code),
            'city_name' => $cityName ?? $code,
            'is_active' => true,
        ]);
    }

    private function createProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices-'.strtolower(fake()->bothify('??##')),
            'is_active' => true,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner-'.strtolower(fake()->bothify('??##')),
            'sku' => 'SCAN-001',
            'description' => 'Compact scanner for warehouse teams.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
