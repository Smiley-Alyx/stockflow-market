<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Read\CatalogReadService;
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
            ->assertJsonPath('data.category.slug', 'devices')
            ->assertJsonPath('data.attributes.0.name', 'color')
            ->assertJsonPath('data.attributes.0.value', 'black');
    }

    public function test_product_endpoint_uses_cache_on_repeated_reads(): void
    {
        $product = $this->createProduct();

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.sku', 'SCAN-001');

        $product->delete();

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
}
