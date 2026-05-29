<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class CatalogApiContractTest extends TestCase
{
    use AssertsOpenApiContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_product_endpoint_matches_gateway_and_catalog_contracts(): void
    {
        $product = $this->createProduct();

        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'name' => 'color',
            'value' => 'black',
        ]);

        $payload = $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->catalogContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/catalog/products/{slug}', '200', 'ProductResponse');
            $this->assertSchemaMatchesPayload($contract, 'ProductResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'Product', $payload['data']);
            $this->assertSchemaMatchesPayload($contract, 'ProductCategory', $payload['data']['category']);
            $this->assertSchemaMatchesPayload($contract, 'ProductAttribute', $payload['data']['attributes'][0]);
        }
    }

    public function test_product_not_found_response_matches_gateway_and_catalog_contracts(): void
    {
        $payload = $this->getJson('/api/catalog/products/missing-product')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->catalogContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/catalog/products/{slug}', '404', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'ErrorResponse', $payload);
        }
    }

    public function test_product_list_endpoint_matches_gateway_and_catalog_contracts(): void
    {
        $this->createProduct();

        $payload = $this->getJson('/api/catalog/products?per_page=10')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->catalogContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/catalog/products', '200', 'ProductListResponse');
            $this->assertSchemaMatchesPayload($contract, 'ProductListResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'PaginationMeta', $payload['meta']);
            $this->assertSchemaMatchesPayload($contract, 'Product', $payload['data'][0]);
            $this->assertSchemaMatchesPayload($contract, 'ProductCategory', $payload['data'][0]['category']);
        }
    }

    public function test_category_tree_endpoint_matches_gateway_and_catalog_contracts(): void
    {
        $devices = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'description' => 'Warehouse devices.',
            'is_active' => true,
        ]);

        Category::query()->create([
            'parent_id' => $devices->id,
            'name' => 'Scanners',
            'slug' => 'scanners',
            'is_active' => true,
        ]);

        $payload = $this->getJson('/api/catalog/categories/tree')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->catalogContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/catalog/categories/tree', '200', 'CategoryTreeResponse');
            $this->assertSchemaMatchesPayload($contract, 'CategoryTreeResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'CategoryNode', $payload['data'][0]);
            $this->assertSchemaMatchesPayload($contract, 'CategoryNode', $payload['data'][0]['children'][0]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function catalogContracts(): array
    {
        return [
            'gateway' => base_path('services/gateway/contracts/openapi.yaml'),
            'catalog' => base_path('services/catalog/contracts/openapi.yaml'),
        ];
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
