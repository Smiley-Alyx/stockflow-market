<?php

namespace App\Domains\Orders\Models;

use App\Domains\Catalog\Models\Product;
use App\Domains\Pricing\Models\ProductPrice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'product_id', 'pricing_product_price_id', 'sku', 'product_name', 'quantity', 'price_type', 'price_city_code', 'price_version', 'price_active_from', 'unit_amount_minor', 'currency', 'line_amount_minor'])]
class OrderItem extends Model
{
    protected $table = 'orders_order_items';

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductPrice, $this>
     */
    public function productPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class, 'pricing_product_price_id');
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_version' => 'integer',
            'price_active_from' => 'datetime',
            'unit_amount_minor' => 'integer',
            'line_amount_minor' => 'integer',
        ];
    }
}
