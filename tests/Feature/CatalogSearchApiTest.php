<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Pricing\Models\ProductPrice;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Infrastructure\Messaging\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_products_endpoint_filters_sorts_and_enriches_elasticsearch_results(): void
    {
        $product = $this->createProduct();

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Wireless Scanner Black',
            'sku' => 'SCAN-001-BLK',
            'attributes' => ['color' => 'black'],
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'sale',
            'city_code' => 'waw',
            'amount_minor' => 99900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $waw = $this->createWarehouse('WAW', 'waw');
        $krk = $this->createWarehouse('KRK', 'krk');

        StockItem::query()->create([
            'warehouse_id' => $waw->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 8,
            'reserved_quantity' => 3,
        ]);

        StockItem::query()->create([
            'warehouse_id' => $krk->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 20,
            'reserved_quantity' => 0,
        ]);

        Http::fake([
            '*/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => ['value' => 1],
                    'hits' => [['_source' => [
                        'id' => $product->id,
                        'name' => 'Wireless Scanner',
                        'slug' => 'wireless-scanner',
                        'sku' => 'SCAN-001',
                        'offers' => [[
                            'id' => 100,
                            'sku' => 'SCAN-001-BLK',
                        ]],
                    ]]],
                ],
                'aggregations' => [
                    'color' => [
                        'buckets' => [
                            ['key' => 'black', 'doc_count' => 1],
                            ['key' => 'white', 'doc_count' => 3],
                        ],
                    ],
                    'memory' => [
                        'buckets' => [
                            ['key' => '128gb', 'doc_count' => 2],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/catalog/products?category=devices&q=SCAN-001&brands[]=acme&filters[color][]=black&filters[color][]=white&filters[memory][]=128gb&in_stock=1&city_code=WAW&sort=price_asc&per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SCAN-001')
            ->assertJsonPath('data.0.offers.0.sku', 'SCAN-001-BLK')
            ->assertJsonPath('data.0.price.amount_minor', 99900)
            ->assertJsonPath('data.0.price.original_amount_minor', 129900)
            ->assertJsonPath('data.0.price.discount_amount_minor', 30000)
            ->assertJsonPath('data.0.price.discount_percent', 23)
            ->assertJsonPath('data.0.price.city_code', 'waw')
            ->assertJsonPath('data.0.availability.in_stock', true)
            ->assertJsonPath('data.0.availability.available_quantity', 5)
            ->assertJsonPath('data.0.availability.city_code', 'waw')
            ->assertJsonPath('meta.sort', 'price_asc')
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.filters.color.0.value', 'black')
            ->assertJsonPath('meta.filters.color.1.count', 3);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'http://elasticsearch:9200/catalog_products/_search'
                && $body['query']['bool']['must'][0]['multi_match']['fields'] === ['name^3', 'sku^3', 'offers.sku^2', 'slug', 'description']
                && in_array(['terms' => ['filters.color.keyword' => ['black', 'white']]], $body['query']['bool']['filter'], true)
                && in_array(['terms' => ['filters.memory.keyword' => ['128gb']]], $body['query']['bool']['filter'], true)
                && in_array(['terms' => ['brand.slug.keyword' => ['acme']]], $body['query']['bool']['filter'], true)
                && in_array(['term' => ['availability.city_codes.keyword' => 'waw']], $body['query']['bool']['filter'], true)
                && $body['sort'][0] === ['price.amount_minor' => ['order' => 'asc', 'missing' => '_last']];
        });
    }

    public function test_products_endpoint_rejects_filter_not_configured_for_category(): void
    {
        $this->createProduct();

        $this->getJson('/api/catalog/products?category=devices&filters[screen][]=oled')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters']);

        Http::assertNothingSent();
    }

    public function test_catalog_search_document_contains_filtering_and_merchandising_data(): void
    {
        $product = $this->createProduct();

        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'name' => 'color',
            'value' => 'black',
        ]);

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Wireless Scanner Black',
            'sku' => 'SCAN-001-BLK',
            'attributes' => ['color' => 'black'],
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'sale',
            'amount_minor' => 99900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        StockItem::query()->create([
            'warehouse_id' => $this->createWarehouse('WAW', 'waw')->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 8,
            'reserved_quantity' => 3,
        ]);

        $this->app->make(CatalogSearchIndexService::class)->requestProduct($product);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::query()
            ->where('event_name', SearchIndexRequested::NAME)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('acme', $message->payload['document']['brand']['slug']);
        $this->assertSame(['black'], $message->payload['document']['filters']['color']);
        $this->assertSame('SCAN-001-BLK', $message->payload['document']['offers'][0]['sku']);
        $this->assertSame(99900, $message->payload['document']['price']['amount_minor']);
        $this->assertSame(129900, $message->payload['document']['price']['original_amount_minor']);
        $this->assertSame(['waw'], $message->payload['document']['availability']['city_codes']);
    }

    public function test_products_endpoint_accepts_only_supported_page_sizes_and_sorts(): void
    {
        $this->getJson('/api/catalog/products?per_page=10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/catalog/products?sort=popular')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    private function createProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'filterable_attributes' => ['color', 'memory'],
            'is_active' => true,
        ]);

        $brand = Brand::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'short_description' => 'Compact scanner.',
            'image_url' => 'https://example.com/scanner.jpg',
            'rating' => 4.75,
            'rating_count' => 24,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function createWarehouse(string $code, string $cityCode): Warehouse
    {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code.' Warehouse',
            'city_code' => $cityCode,
            'city_name' => strtoupper($cityCode),
            'is_active' => true,
        ]);
    }
}
