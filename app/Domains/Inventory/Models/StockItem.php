<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['warehouse_id', 'product_id', 'sku', 'on_hand_quantity', 'reserved_quantity'])]
class StockItem extends Model
{
    protected $table = 'inventory_stock_items';

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function availableQuantity(): int
    {
        return max(0, $this->on_hand_quantity - $this->reserved_quantity);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand_quantity' => 'integer',
            'reserved_quantity' => 'integer',
        ];
    }
}
