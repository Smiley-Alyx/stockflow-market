<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Read\CatalogCacheKeys;
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

        static::deleted(function (): void {
            CatalogCacheKeys::invalidateProducts();
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
