<?php

namespace Tests\Feature;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Events\SearchIndexRequested;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SearchIndexingTest extends TestCase
{
    public function test_it_requests_product_indexing_when_a_product_is_created(): void
    {
        Event::fake([SearchIndexRequested::class]);

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'draft',
        ]);
        $product->id = 15;

        ProductCreated::dispatch($product);

        Event::assertDispatched(SearchIndexRequested::class, function (SearchIndexRequested $event) {
            return $event->index === 'catalog_products'
                && $event->documentId === '15'
                && $event->payload()['event'] === SearchIndexRequested::NAME
                && $event->payload()['document']['sku'] === 'SCAN-001';
        });
    }
}
