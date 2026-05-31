<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'sku', 'status', 'image_url', 'attributes'])]
class ProductOffer extends Model
{
    protected $table = 'catalog_product_offers';

    protected static function booted(): void
    {
        static::saved(function (ProductOffer $offer): void {
            CatalogCacheKeys::invalidateProducts();
            app(CatalogProjectionService::class)->syncProduct($offer->product_id);
        });

        static::deleted(function (ProductOffer $offer): void {
            CatalogCacheKeys::invalidateProducts();
            app(CatalogProjectionService::class)->syncProduct($offer->product_id);
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }
}
