<?php

namespace App\Domains\Search\Listeners;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Search\Events\SearchIndexDeletionRequested;

class RequestProductIndexDeletion
{
    public function handle(ProductArchived $event): void
    {
        SearchIndexDeletionRequested::dispatch(
            index: 'catalog_products',
            documentId: (string) $event->product->id,
        );
    }
}
