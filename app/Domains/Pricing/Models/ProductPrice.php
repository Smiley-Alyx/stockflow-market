<?php

namespace App\Domains\Pricing\Models;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'price_type', 'city_code', 'price_version', 'amount_minor', 'currency', 'is_active', 'active_from', 'active_until'])]
class ProductPrice extends Model
{
    protected $table = 'pricing_product_prices';

    protected static function booted(): void
    {
        static::saving(function (ProductPrice $price): void {
            $cityCode = trim((string) $price->city_code);
            $price->city_code = $cityCode === '' ? null : strtolower($cityCode);
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
            'price_version' => 'integer',
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
            'active_from' => 'datetime',
            'active_until' => 'datetime',
        ];
    }
}
