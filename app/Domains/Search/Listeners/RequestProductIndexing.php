<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Search\Events\SearchIndexRequested;

class RequestProductIndexing
{
    public function handle(ProductCreated $event): void
    {
        $product = $event->product;

        SearchIndexRequested::dispatch(
            index: 'catalog_products',
            documentId: (string) $product->id,
            document: [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'status' => $product->status,
                'published_at' => $product->published_at?->toJSON(),
            ],
        );
    }
}
