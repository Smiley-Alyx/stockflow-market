<?php

namespace App\Domains\Catalog\Events;

use App\Domains\Catalog\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductCreated
{
    use Dispatchable;
    use SerializesModels;

    public const NAME = 'catalog.product.created';

    public function __construct(
        public readonly Product $product,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => self::NAME,
            'product' => [
                'id' => $this->product->id,
                'category_id' => $this->product->category_id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'sku' => $this->product->sku,
                'status' => $this->product->status,
                'published_at' => $this->product->published_at?->toJSON(),
            ],
        ];
    }
}
