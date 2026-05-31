<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use App\Domains\Catalog\Models\ProductFile;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Pricing\Models\ProductPrice;
use App\Domains\Storage\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_product_card_returns_media_documents_price_attributes_and_warehouse_stock(): void
    {
        $category = Category::query()->create([
            'name' => 'Scanners',
            'slug' => 'scanners',
            'card_attribute_names' => ['memory', 'color'],
        ]);
        $brand = Brand::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $mainImage = $this->createFile('https://example.test/scanner-main.jpg', 'image/jpeg');
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'image_file_id' => $mainImage->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'short_description' => 'Compact scanner.',
            'description' => 'Compact scanner for warehouse teams.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        ProductAttribute::query()->insert([
            ['product_id' => $product->id, 'name' => 'color', 'value' => 'black', 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $product->id, 'name' => 'memory', 'value' => '128 MB', 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $product->id, 'name' => 'weight', 'value' => '240 g', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->addProductFile($product, 'https://example.test/gallery-2.jpg', ProductFile::TYPE_GALLERY, 20);
        $this->addProductFile($product, 'https://example.test/gallery-1.jpg', ProductFile::TYPE_GALLERY, 10);
        $this->addProductFile($product, 'https://example.test/manual.pdf', ProductFile::TYPE_INSTRUCTION, 0, 'Manual', 'application/pdf', 2048);
        $this->addProductFile($product, 'https://example.test/certificate.pdf', ProductFile::TYPE_CERTIFICATE, 0, 'Certificate', 'application/pdf', 1024);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'retail',
            'amount_minor' => 129900,
            'currency' => 'USD',
        ]);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => 'sale',
            'amount_minor' => 99900,
            'currency' => 'USD',
        ]);

        $activeWarehouse = $this->createWarehouse('WAW', true);
        $inactiveWarehouse = $this->createWarehouse('OLD', false);
        $this->createStock($product, $activeWarehouse, 10, 3);
        $this->createStock($product, $inactiveWarehouse, 50, 0);

        $this->getJson('/api/catalog/products/wireless-scanner')
            ->assertOk()
            ->assertJsonPath('data.image_url', 'https://example.test/scanner-main.jpg')
            ->assertJsonPath('data.short_description', 'Compact scanner.')
            ->assertJsonPath('data.description', 'Compact scanner for warehouse teams.')
            ->assertJsonPath('data.sku', 'SCAN-001')
            ->assertJsonPath('data.brand.name', 'Acme')
            ->assertJsonPath('data.price.has_discount', true)
            ->assertJsonPath('data.price.amount_minor', 99900)
            ->assertJsonPath('data.price.original_amount_minor', 129900)
            ->assertJsonPath('data.card_attributes.0.name', 'memory')
            ->assertJsonPath('data.card_attributes.1.name', 'color')
            ->assertJsonCount(2, 'data.card_attributes')
            ->assertJsonPath('data.gallery.0.url', 'https://example.test/gallery-1.jpg')
            ->assertJsonPath('data.gallery.1.url', 'https://example.test/gallery-2.jpg')
            ->assertJsonCount(2, 'data.documents')
            ->assertJsonPath('data.documents.0.type', ProductFile::TYPE_CERTIFICATE)
            ->assertJsonPath('data.documents.1.type', ProductFile::TYPE_INSTRUCTION)
            ->assertJsonCount(1, 'data.warehouses')
            ->assertJsonPath('data.warehouses.0.warehouse_code', 'WAW')
            ->assertJsonPath('data.warehouses.0.available_quantity', 7);
    }

    public function test_product_file_rejects_unknown_type(): void
    {
        $this->expectException(ValidationException::class);

        ProductFile::query()->create([
            'product_id' => 1,
            'file_id' => 1,
            'type' => 'unknown',
        ]);
    }

    private function addProductFile(
        Product $product,
        string $url,
        string $type,
        int $position,
        ?string $title = null,
        ?string $mimeType = 'image/jpeg',
        ?int $size = null,
    ): void {
        ProductFile::query()->create([
            'product_id' => $product->id,
            'file_id' => $this->createFile($url, $mimeType, $size)->id,
            'type' => $type,
            'title' => $title,
            'position' => $position,
        ]);
    }

    private function createFile(string $url, ?string $mimeType = null, ?int $size = null): StoredFile
    {
        return StoredFile::query()->create([
            'source_url' => $url,
            'original_name' => basename($url),
            'mime_type' => $mimeType,
            'size' => $size,
        ]);
    }

    private function createWarehouse(string $code, bool $isActive): Warehouse
    {
        return Warehouse::query()->create([
            'code' => $code,
            'name' => $code.' Warehouse',
            'city_code' => strtolower($code),
            'city_name' => $code,
            'is_active' => $isActive,
        ]);
    }

    private function createStock(Product $product, Warehouse $warehouse, int $onHand, int $reserved): void
    {
        StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
        ]);
    }
}
