<?php

namespace Tests\Feature;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\DeadLetterSearchIndexDocument;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Search\ElasticsearchSearchIndexer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    public function test_it_queues_product_indexing_on_the_search_indexing_queue(): void
    {
        Queue::fake();

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'draft',
        ]);
        $product->id = 15;

        ProductCreated::dispatch($product);

        Queue::assertPushedOn('search-indexing', IndexSearchDocument::class);
        Queue::assertPushed(IndexSearchDocument::class, function (IndexSearchDocument $job) {
            return $job->index === 'catalog_products'
                && $job->documentId === '15'
                && $job->document['sku'] === 'SCAN-001'
                && $job->tries === 5
                && $job->timeout === 2
                && $job->backoff() === 1;
        });
    }

    public function test_failed_indexing_job_is_moved_to_the_dead_letter_queue(): void
    {
        Queue::fake();

        $job = new IndexSearchDocument(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
        );

        $job->failed(new RuntimeException('Elasticsearch is unavailable.'));

        Queue::assertPushedOn('search-indexing-dead-letter', DeadLetterSearchIndexDocument::class);
        Queue::assertPushed(DeadLetterSearchIndexDocument::class, function (DeadLetterSearchIndexDocument $job) {
            return $job->index === 'catalog_products'
                && $job->documentId === '15'
                && $job->document['sku'] === 'SCAN-001'
                && $job->attempts === 5
                && $job->failure === 'Elasticsearch is unavailable.';
        });
    }

    public function test_indexing_job_writes_through_the_search_indexer_port(): void
    {
        $indexer = new class implements SearchIndexer
        {
            /**
             * @var array<string, mixed>
             */
            public array $indexed = [];

            /**
             * @param  array<string, mixed>  $document
             */
            public function index(string $index, string $documentId, array $document): void
            {
                $this->indexed = [
                    'index' => $index,
                    'document_id' => $documentId,
                    'document' => $document,
                ];
            }
        };

        $job = new IndexSearchDocument(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
        );

        $job->handle($indexer);

        $this->assertSame([
            'index' => 'catalog_products',
            'document_id' => '15',
            'document' => ['sku' => 'SCAN-001'],
        ], $indexer->indexed);
    }

    public function test_elasticsearch_indexer_writes_documents_to_the_configured_cluster(): void
    {
        Http::fake([
            'http://elasticsearch:9200/catalog_products/_doc/15' => Http::response([
                'result' => 'created',
            ]),
        ]);

        $indexer = new ElasticsearchSearchIndexer;

        $indexer->index('catalog_products', '15', [
            'sku' => 'SCAN-001',
            'name' => 'Wireless Scanner',
        ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'http://elasticsearch:9200/catalog_products/_doc/15'
                && $request['sku'] === 'SCAN-001'
                && $request['name'] === 'Wireless Scanner';
        });
    }
}
