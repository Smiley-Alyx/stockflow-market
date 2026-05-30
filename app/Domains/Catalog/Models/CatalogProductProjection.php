<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'product_id',
    'category_id',
    'category_slug',
    'category_is_active',
    'name',
    'slug',
    'sku',
    'status',
    'in_stock',
    'available_quantity',
    'published_at',
    'payload',
])]
class CatalogProductProjection extends Model
{
    protected $table = 'catalog_product_projections';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_is_active' => 'boolean',
            'in_stock' => 'boolean',
            'available_quantity' => 'integer',
            'published_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
