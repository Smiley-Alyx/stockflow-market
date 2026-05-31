<?php

namespace App\Domains\Storage\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductFile;
use App\Domains\Catalog\Models\ProductOffer;
use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    /** @var Collection<int, int> */
    private Collection $affectedProductIds;

    protected static function booted(): void
    {
        static::saving(function (StoredFile $file): void {
            $file->assertValidLocation();
        });

        static::updated(function (StoredFile $file): void {
            $file->refreshReferences($file->relatedProductIds());
        });

        static::deleting(function (StoredFile $file): void {
            $file->affectedProductIds = $file->relatedProductIds();
        });

        static::deleted(function (StoredFile $file): void {
            $file->refreshReferences($file->affectedProductIds);
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

    private function assertValidLocation(): void
    {
        if ($this->source_url === null && ($this->disk === null || $this->path === null)) {
            throw ValidationException::withMessages([
                'source_url' => 'A source URL or a disk and path pair is required.',
            ]);
        }

        if (($this->disk === null) !== ($this->path === null)) {
            throw ValidationException::withMessages([
                'disk' => 'Disk and path must be specified together.',
            ]);
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function relatedProductIds(): Collection
    {
        return Product::query()
            ->where('image_file_id', $this->id)
            ->orWhereHas('category', fn ($query) => $query->where('image_file_id', $this->id))
            ->orWhereHas('brand', fn ($query) => $query->where('logo_file_id', $this->id))
            ->pluck('id')
            ->merge(
                ProductOffer::query()
                    ->where('image_file_id', $this->id)
                    ->pluck('product_id'),
            )
            ->merge(
                ProductFile::query()
                    ->where('file_id', $this->id)
                    ->pluck('product_id'),
            )
            ->unique();
    }

    /**
     * @param  Collection<int, int>  $productIds
     */
    private function refreshReferences(Collection $productIds): void
    {
        CatalogCacheKeys::invalidateCategoryTree();
        CatalogCacheKeys::invalidateProducts();

        foreach ($productIds as $productId) {
            app(CatalogProjectionService::class)->syncProduct($productId);
            app(CatalogSearchIndexService::class)->requestProduct($productId);
        }
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
