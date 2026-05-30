<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'name', 'slug', 'sku', 'description', 'status', 'published_at'])]
class Product extends Model
{
    protected $table = 'catalog_products';

    protected static function booted(): void
    {
        static::created(function (Product $product): void {
            ProductCreated::dispatch($product);
        });

        static::updated(function (Product $product): void {
            if ($product->status === 'archived') {
                ProductArchived::dispatch($product);

                return;
            }

            ProductUpdated::dispatch($product);
        });

        static::saved(function (): void {
            CatalogCacheKeys::invalidateProducts();
        });

        static::saved(function (Product $product): void {
            app(CatalogProjectionService::class)->syncProduct($product);
        });

        static::deleted(function (Product $product): void {
            CatalogCacheKeys::invalidateProducts();
            app(CatalogProjectionService::class)->deleteProduct($product);
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductAttribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
