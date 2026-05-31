<?php

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Infrastructure\Messaging\DomainEventRecorder;

class CatalogSearchIndexService
{
    public function __construct(
        private readonly CatalogSearchDocumentFactory $documents,
        private readonly DomainEventRecorder $events,
    ) {}

    public function requestProduct(Product|int $product): void
    {
        if (is_int($product)) {
            $product = Product::query()->find($product);
        }

        if (! $product instanceof Product) {
            return;
        }

        $this->events->record(new SearchIndexRequested(
            index: 'catalog_products',
            documentId: (string) $product->id,
            document: $this->documents->make($product),
        ), 'search_document', 'catalog_products:'.$product->id);
    }
}
