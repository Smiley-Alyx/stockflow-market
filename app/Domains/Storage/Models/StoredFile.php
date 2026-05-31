<?php

namespace App\Domains\Storage\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'disk',
    'path',
    'source_url',
    'original_name',
    'mime_type',
    'size',
    'checksum',
    'metadata',
])]
class StoredFile extends Model
{
    protected $table = 'storage_files';

    protected static function booted(): void
    {
        static::updated(function (StoredFile $file): void {
            CatalogCacheKeys::invalidateCategoryTree();
            CatalogCacheKeys::invalidateProducts();

            $productIds = Product::query()
                ->where('image_file_id', $file->id)
                ->orWhereHas('category', fn ($query) => $query->where('image_file_id', $file->id))
                ->orWhereHas('brand', fn ($query) => $query->where('logo_file_id', $file->id))
                ->pluck('id')
                ->merge(
                    ProductOffer::query()
                        ->where('image_file_id', $file->id)
                        ->pluck('product_id'),
                )
                ->unique();

            foreach ($productIds as $productId) {
                app(CatalogProjectionService::class)->syncProduct($productId);
                app(CatalogSearchIndexService::class)->requestProduct($productId);
            }
        });
    }

    public function url(): ?string
    {
        if ($this->source_url !== null) {
            return $this->source_url;
        }

        if ($this->disk === null || $this->path === null) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }
}
