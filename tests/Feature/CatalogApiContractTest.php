<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogApiContractTest extends TestCase
{
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
            $this->assertRequiredKeys($contract, 'ProductResponse', $payload);
            $this->assertRequiredKeys($contract, 'Product', $payload['data']);
            $this->assertRequiredKeys($contract, 'ProductCategory', $payload['data']['category']);
            $this->assertRequiredKeys($contract, 'ProductAttribute', $payload['data']['attributes'][0]);
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
            $this->assertRequiredKeys($contract, 'ErrorResponse', $payload);
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
            $this->assertRequiredKeys($contract, 'CategoryTreeResponse', $payload);
            $this->assertRequiredKeys($contract, 'CategoryNode', $payload['data'][0]);
            $this->assertRequiredKeys($contract, 'CategoryNode', $payload['data'][0]['children'][0]);
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

    private function assertContractDeclaresResponse(string $contract, string $path, string $status, string $schema): void
    {
        $contents = file_get_contents($contract);

        $this->assertIsString($contents);
        $this->assertStringContainsString($path.':', $contents, $contract);
        $this->assertStringContainsString("'".$status."':", $contents, $contract);
        $this->assertStringContainsString("\$ref: '#/components/schemas/".$schema."'", $contents, $contract);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRequiredKeys(string $contract, string $schema, array $payload): void
    {
        foreach ($this->requiredKeys($contract, $schema) as $key) {
            $this->assertArrayHasKey($key, $payload, $contract.' schema '.$schema);
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredKeys(string $contract, string $schema): array
    {
        $contents = file_get_contents($contract);

        $this->assertIsString($contents);
        preg_match(
            '/^    '.preg_quote($schema, '/').":\n(?<body>(?:      .+\n|      \n)*)/m",
            $contents,
            $schemaMatches,
        );

        $this->assertArrayHasKey('body', $schemaMatches, $contract.' schema '.$schema);

        preg_match(
            '/^      required:\n(?<required>(?:        - .+\n)+)/m',
            $schemaMatches['body'],
            $matches,
        );

        $this->assertArrayHasKey('required', $matches, $contract.' schema '.$schema);

        return array_map(
            static fn (string $line): string => preg_replace('/^\s*-\s*/', '', trim($line)),
            array_filter(explode("\n", trim($matches['required']))),
        );
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
