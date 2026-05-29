<?php

namespace Tests\Feature;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Tests\TestCase;

class CatalogModelTest extends TestCase
{
    public function test_it_defines_the_catalog_tables_and_relationships(): void
    {
        $category = new Category;
        $product = new Product;
        $attribute = new ProductAttribute;

        $this->assertSame('catalog_categories', $category->getTable());
        $this->assertSame('catalog_products', $product->getTable());
        $this->assertSame('catalog_product_attributes', $attribute->getTable());
        $this->assertInstanceOf(BelongsTo::class, $category->parent());
        $this->assertInstanceOf(HasMany::class, $category->children());
        $this->assertInstanceOf(HasMany::class, $category->products());
        $this->assertInstanceOf(BelongsTo::class, $product->category());
        $this->assertInstanceOf(HasMany::class, $product->attributes());
        $this->assertInstanceOf(BelongsTo::class, $attribute->product());
    }

    public function test_it_dispatches_a_product_created_event(): void
    {
        Event::fake([ProductCreated::class]);

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'draft',
        ]);
        $product->id = 15;

        $reflection = new ReflectionClass($product);
        $method = $reflection->getMethod('fireModelEvent');
        $method->invoke($product, 'created', false);

        Event::assertDispatched(ProductCreated::class, function (ProductCreated $event) use ($product) {
            return $event->product->is($product)
                && $event->payload()['event'] === ProductCreated::NAME
                && $event->payload()['product']['sku'] === 'SCAN-001';
        });
    }

    public function test_it_dispatches_a_product_updated_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
        ]);
        $product->id = 15;

        $reflection = new ReflectionClass($product);
        $method = $reflection->getMethod('fireModelEvent');
        $method->invoke($product, 'updated', false);

        Event::assertDispatched(ProductUpdated::class, function (ProductUpdated $event) use ($product) {
            return $event->product->is($product)
                && $event->payload()['event'] === ProductUpdated::NAME
                && $event->payload()['product']['sku'] === 'SCAN-001';
        });
    }

    public function test_it_dispatches_a_product_archived_event_for_archived_products(): void
    {
        Event::fake([ProductArchived::class, ProductUpdated::class]);

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'archived',
        ]);
        $product->id = 15;

        $reflection = new ReflectionClass($product);
        $method = $reflection->getMethod('fireModelEvent');
        $method->invoke($product, 'updated', false);

        Event::assertDispatched(ProductArchived::class, function (ProductArchived $event) use ($product) {
            return $event->product->is($product)
                && $event->payload()['event'] === ProductArchived::NAME
                && $event->payload()['product']['sku'] === 'SCAN-001';
        });
        Event::assertNotDispatched(ProductUpdated::class);
    }
}
