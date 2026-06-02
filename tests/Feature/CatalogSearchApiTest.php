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
use App\Domains\Pricing\Read\PricingReadService;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Storage\Models\StoredFile;
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
                    'price_min' => ['value' => 99900],
                    'price_max' => ['value' => 129900],
                ],
            ]),
        ]);

        $this->getJson('/api/catalog/products?category=devices&q=SCAN-001&brands[]=acme&filters[color][]=black&filters[color][]=white&filters[memory][]=128gb&in_stock=1&city_code=WAW&price_from=90000&price_to=140000&sort=price_asc&per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SCAN-001')
            ->assertJsonPath('data.0.offers.0.sku', 'SCAN-001-BLK')
            ->assertJsonPath('data.0.price.amount_minor', 99900)
            ->assertJsonPath('data.0.price.original_amount_minor', 129900)
            ->assertJsonPath('data.0.price.discount_amount_minor', 30000)
            ->assertJsonPath('data.0.price.discount_percent', 23)
            ->assertJsonPath('data.0.price.city_code', 'waw')
            ->assertJsonPath('data.0.price.has_discount', true)
            ->assertJsonPath('data.0.availability.in_stock', true)
            ->assertJsonPath('data.0.availability.available_quantity', 5)
            ->assertJsonPath('data.0.availability.city_code', 'waw')
            ->assertJsonPath('meta.sort', 'price_asc')
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.filters.color.0.value', 'black')
            ->assertJsonPath('meta.filters.color.1.count', 3)
            ->assertJsonPath('meta.filter_url', '/catalog/devices/filter/color-is-black-or-white/memory-is-128gb/price-from-90000-to-140000/apply/')
            ->assertJsonPath('meta.price_range.min', 99900)
            ->assertJsonPath('meta.price_range.max', 129900)
            ->assertJsonPath('meta.price_range.selected_min', 90000)
            ->assertJsonPath('meta.price_range.selected_max', 140000)
            ->assertJsonPath('meta.price_range.url_template', '/catalog/devices/filter/color-is-black-or-white/memory-is-128gb/price-from-{price_from}-to-{price_to}/apply/');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'http://elasticsearch:9200/catalog_products/_search'
                && $body['query']['bool']['must'][0]['multi_match']['fields'] === ['name^3', 'sku^3', 'offers.sku^2', 'slug', 'description']
                && in_array(['terms' => ['filters.color.keyword' => ['black', 'white']]], $body['query']['bool']['filter'], true)
                && in_array(['terms' => ['filters.memory.keyword' => ['128gb']]], $body['query']['bool']['filter'], true)
                && in_array(['terms' => ['brand.slug.keyword' => ['acme']]], $body['query']['bool']['filter'], true)
                && in_array(['term' => ['availability.city_codes.keyword' => 'waw']], $body['query']['bool']['filter'], true)
                && $body['post_filter'] === ['range' => ['price.amount_minor' => ['gte' => 90000, 'lte' => 140000]]]
                && $body['sort'] === [
                    ['price.amount_minor' => ['order' => 'asc', 'missing' => '_last']],
                    ['id' => 'asc'],
                ];
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

    public function test_products_endpoint_resolves_nested_category_path(): void
    {
        $equipment = Category::query()->create([
            'name' => 'Equipment',
            'slug' => 'equipment',
            'is_active' => true,
        ]);
        $devices = Category::query()->create([
            'parent_id' => $equipment->id,
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

        Http::fake([
            '*/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => ['value' => 0],
                    'hits' => [],
                ],
            ]),
        ]);

        $this->getJson('/api/catalog/products?category_path=equipment/devices/scanners')
            ->assertOk()
            ->assertJsonPath('meta.category_path', 'equipment/devices/scanners')
            ->assertJsonPath('meta.canonical_url', '/catalog/equipment/devices/scanners/')
            ->assertJsonPath('meta.breadcrumbs.0.url', '/catalog/equipment/')
            ->assertJsonPath('meta.breadcrumbs.2.url', '/catalog/equipment/devices/scanners/');

        Http::assertSent(function ($request): bool {
            return in_array(
                ['term' => ['category.path.keyword' => 'equipment/devices/scanners']],
                $request->data()['query']['bool']['filter'],
                true,
            );
        });
    }

    public function test_parent_category_includes_descendant_products_and_filters(): void
    {
        $equipment = Category::query()->create([
            'name' => 'Equipment',
            'slug' => 'equipment',
            'filterable_attributes' => ['material'],
            'is_active' => true,
        ]);
        Category::query()->create([
            'parent_id' => $equipment->id,
            'name' => 'Devices',
            'slug' => 'devices',
            'filterable_attributes' => ['color', 'memory'],
            'is_active' => true,
        ]);

        Http::fake([
            '*/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => ['value' => 1],
                    'hits' => [],
                ],
                'aggregations' => [
                    'material' => ['buckets' => []],
                    'color' => ['buckets' => []],
                    'memory' => ['buckets' => []],
                ],
            ]),
        ]);

        $this->getJson('/catalog/equipment/')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure([
                'meta' => [
                    'filters' => ['material', 'color', 'memory'],
                ],
            ]);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return in_array(
                [
                    'bool' => [
                        'should' => [
                            ['term' => ['category.path.keyword' => 'equipment']],
                            ['prefix' => ['category.path.keyword' => 'equipment/']],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
                $body['query']['bool']['filter'],
                true,
            ) && array_keys($body['aggs']) === ['material', 'color', 'memory', 'price_min', 'price_max'];
        });
    }

    public function test_catalog_filter_url_resolves_multiple_values_and_price_range(): void
    {
        $this->createProduct();

        Http::fake([
            '*/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => ['value' => 0],
                    'hits' => [],
                ],
            ]),
        ]);

        $this->getJson('/catalog/devices/filter/color-is-black-or-white/price-from-99900-to-129900/apply/')
            ->assertOk()
            ->assertJsonPath('meta.category_path', 'devices')
            ->assertJsonPath('meta.filter_url', '/catalog/devices/filter/color-is-black-or-white/price-from-99900-to-129900/apply/')
            ->assertJsonPath('meta.price_range.selected_min', 99900)
            ->assertJsonPath('meta.price_range.selected_max', 129900);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return in_array(['terms' => ['filters.color.keyword' => ['black', 'white']]], $body['query']['bool']['filter'], true)
                && $body['post_filter'] === ['range' => ['price.amount_minor' => ['gte' => 99900, 'lte' => 129900]]];
        });
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
        $this->assertTrue($message->payload['document']['price']['has_discount']);
        $this->assertSame(['waw'], $message->payload['document']['availability']['city_codes']);
    }

    public function test_catalog_price_without_sale_keeps_original_price(): void
    {
        $product = $this->createProduct();

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $price = $this->app->make(PricingReadService::class)->catalogPricesForProductIds([$product->id])[$product->id];

        $this->assertFalse($price['has_discount']);
        $this->assertSame(129900, $price['amount_minor']);
        $this->assertSame(129900, $price['original_amount_minor']);
        $this->assertSame(0, $price['discount_amount_minor']);
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

        $image = StoredFile::query()->create([
            'source_url' => 'https://example.com/scanner.jpg',
            'original_name' => 'scanner.jpg',
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'short_description' => 'Compact scanner.',
            'image_file_id' => $image->id,
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
