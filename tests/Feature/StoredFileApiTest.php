<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Homepage\Models\HomepageBlock;
use App\Domains\Storage\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoredFileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_entities_reference_centralized_files_by_id(): void
    {
        $image = $this->createFile('https://example.test/scanner.jpg');
        $logo = $this->createFile('https://example.test/acme.svg');
        $categoryImage = $this->createFile('https://example.test/devices.jpg');
        $offerImage = $this->createFile('https://example.test/scanner-black.jpg');

        $category = Category::query()->create([
            'image_file_id' => $categoryImage->id,
            'name' => 'Devices',
            'slug' => 'devices',
        ]);
        $brand = Brand::query()->create([
            'logo_file_id' => $logo->id,
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'image_file_id' => $image->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'image_file_id' => $offerImage->id,
            'name' => 'Black',
            'sku' => 'SCAN-001-BLACK',
        ]);

        $this->assertFalse(Schema::hasColumn('catalog_products', 'image_url'));
        $this->assertFalse(Schema::hasColumn('catalog_product_offers', 'image_url'));
        $this->assertDatabaseHas('catalog_products', [
            'id' => $product->id,
            'image_file_id' => $image->id,
        ]);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.image_url', 'https://example.test/scanner.jpg')
            ->assertJsonPath('data.category.image_url', 'https://example.test/devices.jpg')
            ->assertJsonPath('data.brand.logo_url', 'https://example.test/acme.svg')
            ->assertJsonPath('data.offers.0.image_url', 'https://example.test/scanner-black.jpg');
    }

    public function test_stored_file_resolves_disk_path_and_refreshes_catalog_projection(): void
    {
        Storage::fake('public');

        $image = StoredFile::query()->create([
            'disk' => 'public',
            'path' => 'catalog/scanner.jpg',
            'original_name' => 'scanner.jpg',
        ]);
        $category = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
        ]);
        Product::query()->create([
            'category_id' => $category->id,
            'image_file_id' => $image->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.image_url', Storage::disk('public')->url('catalog/scanner.jpg'));

        $image->update(['source_url' => 'https://cdn.example.test/scanner.jpg']);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.image_url', 'https://cdn.example.test/scanner.jpg');
    }

    public function test_homepage_banner_resolves_image_from_centralized_file(): void
    {
        $image = $this->createFile('https://example.test/banner.jpg');

        HomepageBlock::query()->create([
            'image_file_id' => $image->id,
            'type' => HomepageBlock::TYPE_BANNER,
            'title' => 'Main banner',
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.0.content.image_url', 'https://example.test/banner.jpg');
    }

    private function createFile(string $url): StoredFile
    {
        return StoredFile::query()->create([
            'source_url' => $url,
            'original_name' => basename($url),
        ]);
    }
}
