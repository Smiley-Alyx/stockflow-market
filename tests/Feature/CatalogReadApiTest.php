<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Read\CatalogReadService;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InventoryService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_product_endpoint_returns_a_product(): void
    {
        $product = $this->createProduct();

        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'name' => 'color',
            'value' => 'black',
        ]);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.name', 'Wireless Scanner')
            ->assertJsonPath('data.slug', 'wireless-scanner')
            ->assertJsonPath('data.availability.in_stock', false)
            ->assertJsonPath('data.availability.available_quantity', 0)
            ->assertJsonPath('data.category.slug', 'devices')
            ->assertJsonPath('data.attributes.0.name', 'color')
            ->assertJsonPath('data.attributes.0.value', 'black');
    }

    public function test_product_endpoint_returns_inventory_availability(): void
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

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.availability.in_stock', true)
            ->assertJsonPath('data.availability.available_quantity', 11);
    }

    public function test_products_endpoint_returns_paginated_published_products(): void
    {
        $devices = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
        ]);

        $archived = Category::query()->create([
            'name' => 'Archived',
            'slug' => 'archived',
            'is_active' => false,
        ]);

        Product::query()->create([
            'category_id' => $devices->id,
            'name' => 'Barcode Printer',
            'slug' => 'barcode-printer',
            'sku' => 'PRN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Product::query()->create([
            'category_id' => $devices->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Product::query()->create([
            'category_id' => $devices->id,
            'name' => 'Draft Terminal',
            'slug' => 'draft-terminal',
            'sku' => 'TERM-001',
            'status' => 'draft',
        ]);

        Product::query()->create([
            'category_id' => $archived->id,
            'name' => 'Legacy Scanner',
            'slug' => 'legacy-scanner',
            'sku' => 'SCAN-LEGACY',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/catalog/products?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'barcode-printer')
            ->assertJsonPath('data.0.availability.in_stock', false)
            ->assertJsonPath('data.0.availability.available_quantity', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['slug' => 'draft-terminal'])
            ->assertJsonMissing(['slug' => 'legacy-scanner']);
    }

    public function test_products_endpoint_filters_by_category_slug(): void
    {
        $devices = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
        ]);

        $supplies = Category::query()->create([
            'name' => 'Supplies',
            'slug' => 'supplies',
            'is_active' => true,
        ]);

        Product::query()->create([
            'category_id' => $devices->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Product::query()->create([
            'category_id' => $supplies->id,
            'name' => 'Label Roll',
            'slug' => 'label-roll',
            'sku' => 'LBL-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/catalog/products?category=supplies')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'label-roll')
            ->assertJsonPath('data.0.category.slug', 'supplies')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['slug' => 'wireless-scanner']);
    }

    public function test_product_endpoint_refreshes_availability_after_stock_change(): void
    {
        $product = $this->createProduct();
        $stockItem = StockItem::query()->create([
            'warehouse_id' => $this->createWarehouse('WAW')->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
        ]);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.availability.in_stock', false)
            ->assertJsonPath('data.availability.available_quantity', 0);

        $this->app->make(InventoryService::class)->receive($stockItem, 5, 'purchase_order', 'PO-1');

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.availability.in_stock', true)
            ->assertJsonPath('data.availability.available_quantity', 5);
    }

    public function test_products_endpoint_rejects_invalid_pagination(): void
    {
        $this->getJson('/api/catalog/products?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_product_endpoint_uses_cache_on_repeated_reads(): void
    {
        $product = $this->createProduct();

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.sku', 'SCAN-001');

        Product::withoutEvents(fn () => $product->delete());

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.sku', 'SCAN-001');
    }

    public function test_category_tree_endpoint_returns_only_active_categories(): void
    {
        $devices = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
        ]);

        Category::query()->create([
            'parent_id' => $devices->id,
            'name' => 'Scanners',
            'slug' => 'scanners',
            'is_active' => true,
        ]);

        Category::query()->create([
            'parent_id' => $devices->id,
            'name' => 'Legacy',
            'slug' => 'legacy',
            'is_active' => false,
        ]);

        Category::query()->create([
            'name' => 'Archived',
            'slug' => 'archived',
            'is_active' => false,
        ]);

        $this->getJson('/api/catalog/categories/tree')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'devices')
            ->assertJsonPath('data.0.children.0.slug', 'scanners')
            ->assertJsonMissing(['slug' => 'legacy'])
            ->assertJsonMissing(['slug' => 'archived']);
    }

    public function test_product_cache_ttl_is_read_from_config(): void
    {
        config(['stockflow.catalog.cache.product_ttl_seconds' => 123]);

        Cache::shouldReceive('get')
            ->once()
            ->with('catalog:products:version', 1)
            ->andReturn(1);

        Cache::shouldReceive('remember')
            ->once()
            ->with('catalog:products:v1:slug:wireless-scanner', 123, \Mockery::type(Closure::class))
            ->andReturn(null);

        $this->app->make(CatalogReadService::class)->productBySlug('wireless-scanner');
    }

    public function test_category_tree_cache_ttl_is_read_from_config(): void
    {
        config(['stockflow.catalog.cache.category_tree_ttl_seconds' => 456]);

        Cache::shouldReceive('get')
            ->once()
            ->with('catalog:categories:tree:version', 1)
            ->andReturn(1);

        Cache::shouldReceive('remember')
            ->once()
            ->with('catalog:categories:tree:v1:active', 456, \Mockery::type(Closure::class))
            ->andReturn([]);

        $this->app->make(CatalogReadService::class)->activeCategoryTree();
    }

    public function test_product_list_cache_ttl_is_read_from_config(): void
    {
        config(['stockflow.catalog.cache.product_ttl_seconds' => 789]);

        Cache::shouldReceive('get')
            ->once()
            ->with('catalog:products:version', 1)
            ->andReturn(1);

        Cache::shouldReceive('remember')
            ->once()
            ->with('catalog:products:v1:list:category:devices:page:2:per-page:10', 789, \Mockery::type(Closure::class))
            ->andReturn(['data' => [], 'meta' => [
                'current_page' => 2,
                'last_page' => 1,
                'per_page' => 10,
                'total' => 0,
            ]]);

        $this->app->make(CatalogReadService::class)->productList(2, 10, 'devices');
    }

    private function createProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'description' => 'Compact scanner for warehouse teams.',
            'status' => 'published',
            'published_at' => now(),
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
}
