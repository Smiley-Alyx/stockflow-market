<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Homepage\Models\HomepageBlock;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Storage\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class HomepageApiTest extends TestCase
{
    use AssertsOpenApiContracts;
    use RefreshDatabase;

    public function test_homepage_endpoint_returns_active_blocks_in_configured_order(): void
    {
        $product = $this->createProduct();

        HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_DESCRIPTION,
            'title' => 'About marketplace',
            'position' => 30,
            'settings' => ['body' => 'Warehouse equipment marketplace.'],
        ]);

        $products = HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_RECOMMENDED_PRODUCTS,
            'title' => 'Recommended',
            'position' => 10,
        ]);
        $products->products()->attach($product, ['position' => 0]);

        HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_BANNER,
            'title' => 'Hidden banner',
            'position' => 0,
            'is_active' => false,
            'image_file_id' => $this->createFile('https://example.test/hidden.jpg')->id,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', HomepageBlock::TYPE_RECOMMENDED_PRODUCTS)
            ->assertJsonPath('data.0.content.products.0.slug', 'wireless-scanner')
            ->assertJsonPath('data.1.type', HomepageBlock::TYPE_DESCRIPTION)
            ->assertJsonPath('data.1.content.body', 'Warehouse equipment marketplace.')
            ->assertJsonMissing(['title' => 'Hidden banner']);
    }

    public function test_homepage_cities_block_returns_active_warehouse_locations_grouped_by_city(): void
    {
        HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_CITIES,
            'title' => 'Cities',
            'position' => 0,
        ]);

        $this->createWarehouse('WAW-1', 'waw', 'Warsaw', 52.2296756, 21.0122287);
        $this->createWarehouse('WAW-2', 'waw', 'Warsaw', 52.1672365, 20.9678911);
        $this->createWarehouse('KRK', 'krk', 'Krakow', 50.0646501, 19.9449799);
        $this->createWarehouse('GDN', 'gdn', 'Gdansk');
        $this->createWarehouse('POZ', 'poz', 'Poznan', 52.406374, 16.9251681, false);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonCount(2, 'data.0.content.cities')
            ->assertJsonPath('data.0.content.cities.0.code', 'krk')
            ->assertJsonPath('data.0.content.cities.0.latitude', 50.0646501)
            ->assertJsonPath('data.0.content.cities.1.code', 'waw')
            ->assertJsonCount(2, 'data.0.content.cities.1.warehouses')
            ->assertJsonPath('data.0.content.cities.1.warehouses.0.code', 'WAW-1')
            ->assertJsonMissing(['code' => 'GDN'])
            ->assertJsonMissing(['code' => 'POZ']);
    }

    public function test_homepage_product_block_excludes_unpublished_products(): void
    {
        $published = $this->createProduct();
        $draft = $this->createProduct('Draft Terminal', 'draft-terminal', 'TERM-001', 'draft');

        $block = HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_NEW_PRODUCTS,
            'title' => 'New products',
            'position' => 0,
        ]);
        $block->products()->attach([
            $published->id => ['position' => 0],
            $draft->id => ['position' => 1],
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.content.products')
            ->assertJsonPath('data.0.content.products.0.slug', 'wireless-scanner')
            ->assertJsonMissing(['slug' => 'draft-terminal']);
    }

    public function test_homepage_endpoint_matches_gateway_contract(): void
    {
        $file = $this->createFile('https://example.test/banner.jpg');

        HomepageBlock::query()->create([
            'type' => HomepageBlock::TYPE_BANNER,
            'title' => 'Main banner',
            'position' => 0,
            'image_file_id' => $file->id,
        ]);

        $payload = $this->getJson('/api/homepage')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        $contract = base_path('services/gateway/contracts/openapi.yaml');

        $this->assertContractDeclaresResponse($contract, '/api/homepage', '200', 'HomepageResponse');
        $this->assertSchemaMatchesPayload($contract, 'HomepageResponse', $payload);
        $this->assertSchemaMatchesPayload($contract, 'HomepageBlock', $payload['data'][0]);
        $this->assertSame('https://example.test/banner.jpg', $payload['data'][0]['content']['image_url']);
    }

    private function createProduct(
        string $name = 'Wireless Scanner',
        string $slug = 'wireless-scanner',
        string $sku = 'SCAN-001',
        string $status = 'published',
    ): Product {
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
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function createWarehouse(
        string $code,
        string $cityCode,
        string $cityName,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $isActive = true,
    ): Warehouse {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code.' Warehouse',
            'city_code' => $cityCode,
            'city_name' => $cityName,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_active' => $isActive,
        ]);
    }

    private function createFile(string $url): StoredFile
    {
        return StoredFile::query()->create([
            'source_url' => $url,
            'original_name' => basename($url),
        ]);
    }
}
