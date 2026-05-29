<?php

namespace Tests\Feature;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Events\SearchIndexCompleted;
use App\Domains\Search\Events\SearchIndexFailed;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Search\DeadLetters\ArraySearchIndexDeadLetterStore;
use App\Infrastructure\Search\ElasticsearchSearchIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SearchIndexingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['stockflow.search.indexing.dead_letter_backend' => 'array']);
        ArraySearchIndexDeadLetterStore::reset();
        $this->app->bind(SearchIndexDeadLetterStore::class, ArraySearchIndexDeadLetterStore::class);
    }

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
        Event::fake([SearchIndexFailed::class]);

        $job = new IndexSearchDocument(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
        );

        $job->failed(new RuntimeException('Elasticsearch is unavailable.'));

        $deadLetter = $this->app->make(SearchIndexDeadLetterStore::class)->find(1);

        $this->assertNotNull($deadLetter);
        $this->assertSame('catalog_products', $deadLetter->index);
        $this->assertSame('15', $deadLetter->documentId);
        $this->assertSame('SCAN-001', $deadLetter->document['sku']);
        $this->assertSame(5, $deadLetter->attempts);
        $this->assertSame('Elasticsearch is unavailable.', $deadLetter->failure);

        Event::assertDispatched(SearchIndexFailed::class, function (SearchIndexFailed $event) {
            return $event->index === 'catalog_products'
                && $event->documentId === '15'
                && $event->attempts === 5
                && $event->failure === 'Elasticsearch is unavailable.'
                && $event->payload()['event'] === SearchIndexFailed::NAME;
        });
    }

    public function test_dead_letter_command_lists_and_requeues_search_indexing_jobs(): void
    {
        $this->artisan('migrate:fresh --force')->assertExitCode(0);

        config(['queue.default' => 'database']);

        $deadLetterJob = $this->app->make(SearchIndexDeadLetterStore::class)->put(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
            attempts: 5,
            failure: 'Elasticsearch is unavailable.',
        );

        $this->artisan('search:dead-letter list')
            ->expectsTable(
                ['ID', 'Index', 'Document ID', 'Attempts', 'Failure'],
                [[$deadLetterJob->id, 'catalog_products', '15', 5, 'Elasticsearch is unavailable.']],
            )
            ->assertExitCode(0);

        $this->artisan('search:dead-letter requeue --id='.$deadLetterJob->id)
            ->expectsOutput('Search indexing job requeued.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('jobs', [
            'queue' => 'search-indexing-dead-letter',
        ]);

        $this->assertDatabaseHas('jobs', [
            'queue' => 'search-indexing',
        ]);
    }

    public function test_dead_letter_requeue_can_dry_run_with_index_and_document_filters(): void
    {
        $this->artisan('migrate:fresh --force')->assertExitCode(0);

        config(['queue.default' => 'database']);

        $firstDeadLetter = $this->app->make(SearchIndexDeadLetterStore::class)->put(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
            attempts: 5,
            failure: 'Elasticsearch is unavailable.',
        );
        $this->app->make(SearchIndexDeadLetterStore::class)->put(
            index: 'catalog_products',
            documentId: '16',
            document: ['sku' => 'SCAN-002'],
            attempts: 5,
            failure: 'Elasticsearch is unavailable.',
        );

        $this->artisan('search:dead-letter requeue --all --dry-run --index=catalog_products --document-id=15')
            ->expectsTable(
                ['ID', 'Index', 'Document ID', 'Attempts', 'Failure'],
                [[$firstDeadLetter->id, 'catalog_products', '15', 5, 'Elasticsearch is unavailable.']],
            )
            ->expectsOutput('Dry run: 1 search indexing job(s) matched.')
            ->assertExitCode(0);

        $this->assertSame(2, $this->app->make(SearchIndexDeadLetterStore::class)->list(limit: 10)->count());
    }

    public function test_dead_letter_bulk_requeue_requires_confirmation(): void
    {
        $this->artisan('migrate:fresh --force')->assertExitCode(0);

        config(['queue.default' => 'database']);

        $this->app->make(SearchIndexDeadLetterStore::class)->put(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
            attempts: 5,
            failure: 'Elasticsearch is unavailable.',
        );
        $this->app->make(SearchIndexDeadLetterStore::class)->put(
            index: 'catalog_products',
            documentId: '16',
            document: ['sku' => 'SCAN-002'],
            attempts: 5,
            failure: 'Elasticsearch is unavailable.',
        );

        $this->artisan('search:dead-letter requeue --all --index=catalog_products')
            ->expectsConfirmation('Requeue 2 search indexing dead-letter jobs?', 'no')
            ->expectsOutput('Requeue cancelled.')
            ->assertExitCode(0);

        $this->assertSame(2, $this->app->make(SearchIndexDeadLetterStore::class)->list(limit: 10)->count());

        $this->artisan('search:dead-letter requeue --all --index=catalog_products')
            ->expectsConfirmation('Requeue 2 search indexing dead-letter jobs?', 'yes')
            ->expectsOutput('Search indexing jobs requeued: 2.')
            ->assertExitCode(0);

        $this->assertTrue($this->app->make(SearchIndexDeadLetterStore::class)->list()->isEmpty());

        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_dead_letter_bulk_requeue_uses_bounded_batches(): void
    {
        $this->artisan('migrate:fresh --force')->assertExitCode(0);

        config([
            'queue.default' => 'database',
            'stockflow.search.indexing.requeue_batch_size' => 3,
            'stockflow.search.indexing.max_requeue_batch_size' => 5,
        ]);

        for ($documentId = 1; $documentId <= 7; $documentId++) {
            $this->app->make(SearchIndexDeadLetterStore::class)->put(
                index: 'catalog_products',
                documentId: (string) $documentId,
                document: ['sku' => 'SCAN-'.$documentId],
                attempts: 5,
                failure: 'Elasticsearch is unavailable.',
            );
        }

        $this->artisan('search:dead-letter requeue --all --index=catalog_products --batch-size=99 --force')
            ->expectsOutput('Search indexing jobs requeued: 7.')
            ->assertExitCode(0);

        $this->assertTrue($this->app->make(SearchIndexDeadLetterStore::class)->list(limit: 10)->isEmpty());
        $this->assertDatabaseCount('jobs', 7);
    }

    public function test_queue_worker_retries_indexing_job_until_dead_letter_threshold(): void
    {
        $this->artisan('migrate:fresh --force')->assertExitCode(0);

        config([
            'queue.default' => 'database',
            'stockflow.messaging.retry.max_attempts' => 3,
            'stockflow.messaging.retry.backoff_ms' => 0,
            'stockflow.messaging.retry.dead_letter_after_attempts' => 3,
        ]);

        $indexer = new class implements SearchIndexer
        {
            public int $attempts = 0;

            /**
             * @param  array<string, mixed>  $document
             */
            public function index(string $index, string $documentId, array $document): void
            {
                $this->attempts++;

                throw new RuntimeException('Elasticsearch is unavailable.');
            }
        };

        $this->app->instance(SearchIndexer::class, $indexer);

        IndexSearchDocument::dispatch(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
        );

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->artisan('queue:work database --queue=search-indexing --once --sleep=0 --tries=3 --backoff=0 --timeout=0')
                ->assertExitCode(0);
        }

        $this->assertSame(3, $indexer->attempts);
        $this->assertDatabaseMissing('jobs', [
            'queue' => 'search-indexing',
        ]);
        $deadLetter = $this->app->make(SearchIndexDeadLetterStore::class)->find(1);

        $this->assertNotNull($deadLetter);
        $this->assertSame(3, $deadLetter->attempts);
    }

    public function test_indexing_job_writes_through_the_search_indexer_port(): void
    {
        Event::fake([SearchIndexCompleted::class]);

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

        Event::assertDispatched(SearchIndexCompleted::class, function (SearchIndexCompleted $event) {
            return $event->index === 'catalog_products'
                && $event->documentId === '15'
                && $event->payload()['event'] === SearchIndexCompleted::NAME;
        });
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
