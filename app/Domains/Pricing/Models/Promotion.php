<?php

namespace App\Domains\Pricing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'discount_type', 'discount_value', 'currency', 'is_active', 'starts_at', 'ends_at'])]
class Promotion extends Model
{
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';

    public const TYPE_PERCENT = 'percent';

    protected $table = 'pricing_promotions';

    protected static function booted(): void
    {
        static::saving(function (Promotion $promotion): void {
            $promotion->code = strtoupper(trim($promotion->code));
        });
    }

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
