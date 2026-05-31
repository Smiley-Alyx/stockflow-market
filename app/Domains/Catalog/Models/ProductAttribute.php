<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'value'])]
class ProductAttribute extends Model
{
    protected $table = 'catalog_product_attributes';

    protected static function booted(): void
    {
        static::saved(function (): void {
            CatalogCacheKeys::invalidateProducts();
        });

        static::saved(function (ProductAttribute $attribute): void {
            app(CatalogProjectionService::class)->syncAttribute($attribute);
            app(CatalogSearchIndexService::class)->requestProduct($attribute->product_id);
        });

        static::deleted(function (ProductAttribute $attribute): void {
            CatalogCacheKeys::invalidateProducts();
            app(CatalogProjectionService::class)->syncAttribute($attribute);
            app(CatalogSearchIndexService::class)->requestProduct($attribute->product_id);
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
