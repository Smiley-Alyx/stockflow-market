<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Pricing\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class PricingApiTest extends TestCase
{
    use AssertsOpenApiContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_prices_endpoint_returns_active_prices_for_product_ids(): void
    {
        $scanner = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $printer = $this->createProduct('Barcode Printer', 'barcode-printer', 'PRN-001');
        $inactive = $this->createProduct('Legacy Scanner', 'legacy-scanner', 'SCAN-LEGACY');

        ProductPrice::query()->create([
            'product_id' => $scanner->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $printer->id,
            'price_type' => 'retail',
            'amount_minor' => 49900,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $inactive->id,
            'price_type' => 'retail',
            'amount_minor' => 29900,
            'currency' => 'USD',
            'is_active' => false,
        ]);

        $this->getJson('/api/pricing/prices?product_ids[]='.$printer->id.'&product_ids[]='.$scanner->id.'&product_ids[]='.$inactive->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.product_id', $scanner->id)
            ->assertJsonPath('data.0.price_type', 'retail')
            ->assertJsonPath('data.0.city_code', null)
            ->assertJsonPath('data.0.price_version', 1)
            ->assertJsonPath('data.0.amount_minor', 129900)
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.1.product_id', $printer->id)
            ->assertJsonPath('data.1.amount_minor', 49900)
            ->assertJsonPath('data.1.currency', 'EUR')
            ->assertJsonMissing(['product_id' => $inactive->id]);
    }

    public function test_prices_endpoint_returns_multiple_price_types_and_can_filter_them(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'wholesale',
            'amount_minor' => 119900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->getJson('/api/pricing/prices?product_ids[]='.$product->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price_type', 'retail')
            ->assertJsonPath('data.1.price_type', 'wholesale');

        $this->getJson('/api/pricing/prices?product_ids[]='.$product->id.'&price_types[]=wholesale')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price_type', 'wholesale')
            ->assertJsonPath('data.0.amount_minor', 119900);
    }

    public function test_prices_endpoint_prefers_city_price_and_ignores_future_versions(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'price_version' => 1,
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
            'active_from' => now()->subDay(),
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'city_code' => ' WAW ',
            'price_version' => 2,
            'amount_minor' => 119900,
            'currency' => 'USD',
            'is_active' => true,
            'active_from' => now()->subHour(),
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'city_code' => 'waw',
            'price_version' => 3,
            'amount_minor' => 9900,
            'currency' => 'USD',
            'is_active' => true,
            'active_from' => now()->addHour(),
        ]);

        $this->getJson('/api/pricing/prices?product_ids[]='.$product->id.'&city_code=WAW')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city_code', 'waw')
            ->assertJsonPath('data.0.price_version', 2)
            ->assertJsonPath('data.0.amount_minor', 119900);

        $this->getJson('/api/pricing/prices?product_ids[]='.$product->id.'&city_code=krk')
            ->assertOk()
            ->assertJsonPath('data.0.city_code', null)
            ->assertJsonPath('data.0.price_version', 1)
            ->assertJsonPath('data.0.amount_minor', 129900);
    }

    public function test_prices_endpoint_matches_gateway_and_pricing_contracts(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $payload = $this->getJson('/api/pricing/prices?product_ids[]='.$product->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->pricingContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/pricing/prices', '200', 'PriceListResponse');
            $this->assertContractDeclaresResponse($contract, '/api/pricing/prices', '422', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'PriceListResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'ProductPrice', $payload['data'][0]);
        }
    }

    public function test_prices_endpoint_requires_product_ids(): void
    {
        $this->getJson('/api/pricing/prices')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_ids']);
    }

    /**
     * @return array<string, string>
     */
    private function pricingContracts(): array
    {
        return [
            'gateway' => base_path('services/gateway/contracts/openapi.yaml'),
            'pricing' => base_path('services/pricing/contracts/openapi.yaml'),
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
}
