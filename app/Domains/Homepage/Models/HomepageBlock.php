<?php

namespace App\Domains\Homepage\Models;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['type', 'title', 'position', 'is_active', 'settings'])]
class HomepageBlock extends Model
{
    public const TYPE_RECOMMENDED_PRODUCTS = 'recommended_products';

    public const TYPE_BESTSELLER_PRODUCTS = 'bestseller_products';

    public const TYPE_NEW_PRODUCTS = 'new_products';

    public const TYPE_DESCRIPTION = 'description';

    public const TYPE_CITIES = 'cities';

    public const TYPE_BANNER = 'banner';

    protected $table = 'homepage_blocks';

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_RECOMMENDED_PRODUCTS => 'Recommended products',
            self::TYPE_BESTSELLER_PRODUCTS => 'Bestseller products',
            self::TYPE_NEW_PRODUCTS => 'New products',
            self::TYPE_DESCRIPTION => 'Description',
            self::TYPE_CITIES => 'Cities',
            self::TYPE_BANNER => 'Banner',
        ];
    }

    public function isProductBlock(): bool
    {
        return in_array($this->type, [
            self::TYPE_RECOMMENDED_PRODUCTS,
            self::TYPE_BESTSELLER_PRODUCTS,
            self::TYPE_NEW_PRODUCTS,
        ], true);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'homepage_block_products')
            ->withPivot('position')
            ->orderByPivot('position');
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
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }
}
