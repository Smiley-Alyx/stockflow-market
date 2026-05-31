<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Catalog\Search\CatalogSearchIndexService;
use App\Domains\Storage\Models\StoredFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_id', 'file_id', 'type', 'title', 'position'])]
class ProductFile extends Model
{
    public const TYPE_GALLERY = 'gallery';

    public const TYPE_INSTRUCTION = 'instruction';

    public const TYPE_CERTIFICATE = 'certificate';

    public const TYPE_ATTACHMENT = 'attachment';

    protected $table = 'catalog_product_files';

    protected static function booted(): void
    {
        static::saving(function (ProductFile $file): void {
            if (! array_key_exists($file->type, self::typeOptions())) {
                throw ValidationException::withMessages([
                    'type' => 'Unsupported product file type.',
                ]);
            }
        });

        static::saved(function (ProductFile $file): void {
            $file->syncProduct();
        });

        static::deleted(function (ProductFile $file): void {
            $file->syncProduct();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_GALLERY => 'Gallery image',
            self::TYPE_INSTRUCTION => 'Instruction',
            self::TYPE_CERTIFICATE => 'Certificate',
            self::TYPE_ATTACHMENT => 'Attachment',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    private function syncProduct(): void
    {
        CatalogCacheKeys::invalidateProducts();
        app(CatalogProjectionService::class)->syncProduct($this->product_id);
        app(CatalogSearchIndexService::class)->requestProduct($this->product_id);
    }
}
