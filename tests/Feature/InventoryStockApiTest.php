<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InsufficientStock;
use App\Domains\Inventory\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
            ->assertJsonPath('data.warehouses.0.available_quantity', 7)
            ->assertJsonPath('data.warehouses.1.warehouse_code', 'KRK')
            ->assertJsonPath('data.warehouses.1.available_quantity', 4);
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

    public function test_stock_endpoint_requires_sku_or_product_id(): void
    {
        $this->getJson('/api/inventory/stock')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'product_id']);
    }

    public function test_inventory_service_reserves_deducts_and_returns_stock(): void
    {
        Event::fake([StockChanged::class]);

        $stockItem = $this->createStockItem(onHand: 10);
        $inventory = $this->app->make(InventoryService::class);

        $reservation = $inventory->reserve($stockItem, 4, 'order', 'ORD-1');
        $stockItem->refresh();

        $this->assertSame(10, $stockItem->on_hand_quantity);
        $this->assertSame(4, $stockItem->reserved_quantity);
        $this->assertSame(6, $stockItem->availableQuantity());
        $this->assertSame(StockMovement::TYPE_RESERVED, $reservation->type);

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

        Event::assertDispatchedTimes(StockChanged::class, 3);
        Event::assertDispatched(StockChanged::class, function (StockChanged $event): bool {
            return $event->payload()['event'] === StockChanged::NAME
                && $event->payload()['stock']['sku'] === 'SCAN-001'
                && $event->payload()['movement']['type'] === StockMovement::TYPE_RETURNED;
        });
    }

    public function test_inventory_service_rejects_reservation_over_available_stock(): void
    {
        $stockItem = $this->createStockItem(onHand: 2);

        $this->expectException(InsufficientStock::class);

        $this->app->make(InventoryService::class)->reserve($stockItem, 3);
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

    private function createWarehouse(string $code): Warehouse
    {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code.' Warehouse',
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
