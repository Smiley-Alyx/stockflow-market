<?php

namespace Tests\Unit;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogCacheKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_versions_product_cache_keys(): void
    {
        $this->assertSame(
            'catalog:products:v1:slug:wireless-scanner',
            CatalogCacheKeys::productBySlug('wireless-scanner'),
        );

        $this->assertSame(
            'catalog:products:v1:list:category:devices:page:2:per-page:10',
            CatalogCacheKeys::productList(2, 10, 'devices'),
        );

        CatalogCacheKeys::invalidateProducts();

        $this->assertSame(
            'catalog:products:v2:slug:wireless-scanner',
            CatalogCacheKeys::productBySlug('wireless-scanner'),
        );

        $this->assertSame(
            'catalog:products:v2:list:category:all:page:1:per-page:20',
            CatalogCacheKeys::productList(1, 20, null),
        );
    }

    public function test_it_versions_category_tree_cache_keys(): void
    {
        $this->assertSame(
            'catalog:categories:tree:v1:active',
            CatalogCacheKeys::activeCategoryTree(),
        );

        CatalogCacheKeys::invalidateCategoryTree();

        $this->assertSame(
            'catalog:categories:tree:v2:active',
            CatalogCacheKeys::activeCategoryTree(),
        );
    }
}
