<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
