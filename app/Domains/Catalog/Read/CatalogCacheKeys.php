<?php

namespace App\Domains\Catalog\Read;

use Illuminate\Support\Facades\Cache;

class CatalogCacheKeys
{
    private const PRODUCT_VERSION_KEY = 'catalog:products:version';

    private const CATEGORY_TREE_VERSION_KEY = 'catalog:categories:tree:version';

    public static function productBySlug(string $slug): string
    {
        return sprintf('catalog:products:v%d:slug:%s', self::version(self::PRODUCT_VERSION_KEY), $slug);
    }

    public static function activeCategoryTree(): string
    {
        return sprintf('catalog:categories:tree:v%d:active', self::version(self::CATEGORY_TREE_VERSION_KEY));
    }

    public static function invalidateProducts(): void
    {
        self::bump(self::PRODUCT_VERSION_KEY);
    }

    public static function invalidateCategoryTree(): void
    {
        self::bump(self::CATEGORY_TREE_VERSION_KEY);
    }

    private static function version(string $key): int
    {
        return (int) Cache::get($key, 1);
    }

    private static function bump(string $key): void
    {
        Cache::forever($key, self::version($key) + 1);
    }
}
