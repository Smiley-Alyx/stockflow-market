<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Read\CatalogCacheKeys;
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

        static::saved(function (): void {
            CatalogCacheKeys::invalidateProducts();
        });

        static::deleted(function (): void {
            CatalogCacheKeys::invalidateProducts();
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
