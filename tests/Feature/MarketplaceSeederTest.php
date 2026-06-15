<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_seeder_defaults_to_fifty_thousand_products(): void
    {
        $this->assertSame(50000, config('stockflow.seed.product_count'));
    }

    public function test_marketplace_seeder_builds_complete_idempotent_demo_catalog(): void
    {
        config([
            'stockflow.seed.product_count' => 250,
            'stockflow.seed.search_index' => false,
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('catalog_categories', 12);
        $this->assertDatabaseCount('catalog_brands', 6);
        $this->assertDatabaseCount('catalog_products', 250);
        $this->assertDatabaseCount('catalog_product_projections', 250);
        $this->assertDatabaseCount('inventory_warehouses', 7);
        $this->assertDatabaseCount('inventory_stock_items', 1750);
        $this->assertDatabaseCount('pricing_product_prices', 947);
        $this->assertDatabaseCount('pricing_promotions', 3);
        $this->assertDatabaseCount('homepage_blocks', 6);
        $this->assertDatabaseCount('storage_files', 68);
        $this->assertGreaterThanOrEqual(8, DB::table('catalog_product_attributes')->where('name', 'color')->distinct()->count('value'));
        $this->assertGreaterThanOrEqual(10, DB::table('catalog_product_attributes')->where('name', 'size')->distinct()->count('value'));
        $this->assertGreaterThan(
            10000,
            DB::table('pricing_product_prices')->where('price_type', 'retail')->max('amount_minor')
                - DB::table('pricing_product_prices')->where('price_type', 'retail')->min('amount_minor'),
        );

        $headphonesId = (int) DB::table('catalog_products')
            ->where('slug', 'headphones-wave')
            ->value('id');

        $this->assertDatabaseHas('pricing_product_prices', [
            'product_id' => $headphonesId,
            'price_type' => 'partner',
            'currency' => 'PLN',
        ]);
        $this->assertDatabaseHas('catalog_product_offers', [
            'product_id' => $headphonesId,
            'sku' => 'AUR-WAVE-ANC-GR',
        ]);
        $this->assertDatabaseHas('catalog_product_attributes', [
            'product_id' => $headphonesId,
            'name' => 'connection',
            'value' => 'Bluetooth 5.3',
        ]);
        $this->assertFileExists(public_path('images/seed/product-headphones-wave.svg'));

        $this->getJson('/api/catalog/products/headphones-wave')
            ->assertOk()
            ->assertJsonPath('data.image_url', '/images/seed/product-headphones-wave.svg')
            ->assertJsonPath('data.brand.name', 'Aurora')
            ->assertJsonPath('data.offers.0.sku', 'AUR-WAVE-ANC-GR')
            ->assertJsonCount(1, 'data.gallery')
            ->assertJsonCount(7, 'data.warehouses');

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.type', 'banner')
            ->assertJsonPath('data.4.type', 'cities')
            ->assertJsonCount(5, 'data.4.content.cities');
    }
}
