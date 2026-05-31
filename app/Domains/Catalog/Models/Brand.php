<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use App\Domains\Storage\Models\StoredFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['logo_file_id', 'name', 'slug', 'description'])]
class Brand extends Model
{
    protected $table = 'catalog_brands';

    protected static function booted(): void
    {
        static::saved(function (Brand $brand): void {
            CatalogCacheKeys::invalidateProducts();
            $brand->syncProductProjections();
        });

        static::deleted(function (): void {
            CatalogCacheKeys::invalidateProducts();
        });
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function logoFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'logo_file_id');
    }

    private function syncProductProjections(): void
    {
        $this->products()
            ->orderBy('id')
            ->chunkById(100, function (Collection $products): void {
                foreach ($products as $product) {
                    app(CatalogProjectionService::class)->syncProduct($product->id);
                    app(CatalogSearchIndexService::class)->requestProduct($product->id);
                }
            });
    }
}
