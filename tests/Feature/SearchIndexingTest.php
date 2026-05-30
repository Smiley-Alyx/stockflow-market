<?php

namespace Tests\Feature;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Events\SearchIndexCompleted;
use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Events\SearchIndexFailed;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\DeleteSearchDocument;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\DomainEventPublisher;
use App\Infrastructure\Messaging\OutboxMessage;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Search\DeadLetters\ArraySearchIndexDeadLetterStore;
use App\Infrastructure\Search\ElasticsearchProductSearch;
use App\Infrastructure\Search\ElasticsearchSearchIndexer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SearchIndexingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stockflow.search.indexing.dead_letter_backend' => 'array']);
        ArraySearchIndexDeadLetterStore::reset();
        $this->app->bind(SearchIndexDeadLetterStore::class, ArraySearchIndexDeadLetterStore::class);
    }

    public function test_it_requests_product_indexing_when_a_product_is_created(): void
    {
        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'draft',
        ]);
        $product->id = 15;

        ProductCreated::dispatch($product);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::query()
            ->where('event_name', SearchIndexRequested::NAME)
            ->firstOrFail();

        $this->assertSame('search_document', $message->aggregate_type);
        $this->assertSame('catalog_products:15', $message->aggregate_id);
        $this->assertSame('SCAN-001', $message->payload['document']['sku']);
    }

    public function test_it_requests_product_indexing_when_a_product_is_updated(): void
    {
        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner Pro',
            'slug' => 'wireless-scanner-pro',
            'sku' => 'SCAN-001',
            'status' => 'published',
        ]);
        $product->id = 15;

        ProductUpdated::dispatch($product);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::query()
            ->where('event_name', SearchIndexRequested::NAME)
            ->firstOrFail();

        $this->assertSame('15', $message->payload['document_id']);
        $this->assertSame('Wireless Scanner Pro', $message->payload['document']['name']);
    }

    public function test_it_requests_product_index_deletion_when_a_product_is_archived(): void
    {
        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'archived',
        ]);
        $product->id = 15;

        ProductArchived::dispatch($product);

        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => SearchIndexDeletionRequested::NAME,
            'aggregate_type' => 'search_document',
            'aggregate_id' => 'catalog_products:15',
        ]);
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

        $this->app->make(DomainEventPublisher::class)->publishPending();

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

    public function test_it_queues_product_index_deletion_on_the_search_indexing_queue(): void
    {
        Queue::fake();

        $product = new Product([
            'category_id' => 7,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'archived',
        ]);
        $product->id = 15;

        ProductArchived::dispatch($product);

        $this->app->make(DomainEventPublisher::class)->publishPending();

        Queue::assertPushedOn('search-indexing', DeleteSearchDocument::class);
        Queue::assertPushed(DeleteSearchDocument::class, function (DeleteSearchDocument $job) {
            return $job->index === 'catalog_products'
                && $job->documentId === '15'
                && $job->tries === 5
                && $job->timeout === 2
                && $job->backoff() === 1;
        });
    }

    public function test_open_rabbitmq_circuit_leaves_indexing_events_pending_in_outbox(): void
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
        $this->app->make(CircuitBreaker::class)->open('rabbitmq');

        $published = $this->app->make(DomainEventPublisher::class)->publishPending();

        $this->assertSame(0, $published);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('messaging_outbox', [
            'event_name' => SearchIndexRequested::NAME,
            'status' => OutboxMessage::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }

    public function test_search_index_consumer_ignores_duplicate_message_delivery(): void
    {
        Queue::fake();

        $event = new SearchIndexRequested(
            index: 'catalog_products',
            documentId: '15',
            document: ['sku' => 'SCAN-001'],
        );

        DomainEventContext::withMessageId('outbox-1', fn () => SearchIndexRequested::dispatch(
            index: $event->index,
            documentId: $event->documentId,
            document: $event->document,
        ));
        DomainEventContext::withMessageId('outbox-1', fn () => SearchIndexRequested::dispatch(
            index: $event->index,
            documentId: $event->documentId,
            document: $event->document,
        ));

        Queue::assertPushed(IndexSearchDocument::class, 1);
        $this->assertDatabaseHas('messaging_inbox', [
            'message_id' => 'outbox-1',
            'consumer' => 'App\Domains\Search\Listeners\DispatchSearchIndexJob',
            'status' => 'processed',
        ]);
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

            public function delete(string $index, string $documentId): void
            {
                //
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

            public function delete(string $index, string $documentId): void
            {
                //
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

    public function test_delete_job_deletes_through_the_search_indexer_port(): void
    {
        Event::fake([SearchIndexCompleted::class]);

        $indexer = new class implements SearchIndexer
        {
            /**
             * @var array<string, string>
             */
            public array $deleted = [];

            /**
             * @param  array<string, mixed>  $document
             */
            public function index(string $index, string $documentId, array $document): void
            {
                //
            }

            public function delete(string $index, string $documentId): void
            {
                $this->deleted = [
                    'index' => $index,
                    'document_id' => $documentId,
                ];
            }
        };

        $job = new DeleteSearchDocument(
            index: 'catalog_products',
            documentId: '15',
        );

        $job->handle($indexer);

        $this->assertSame([
            'index' => 'catalog_products',
            'document_id' => '15',
        ], $indexer->deleted);

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

    public function test_elasticsearch_indexer_deletes_documents_from_the_configured_cluster(): void
    {
        Http::fake([
            'http://elasticsearch:9200/catalog_products/_doc/15' => Http::response([
                'result' => 'deleted',
            ]),
        ]);

        $indexer = new ElasticsearchSearchIndexer;

        $indexer->delete('catalog_products', '15');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === 'http://elasticsearch:9200/catalog_products/_doc/15';
        });
    }

    public function test_product_search_endpoint_reads_from_elasticsearch(): void
    {
        Http::fake([
            'http://elasticsearch:9200/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => ['value' => 1],
                    'hits' => [[
                        '_source' => [
                            'id' => 15,
                            'name' => 'Wireless Scanner',
                            'slug' => 'wireless-scanner',
                            'sku' => 'SCAN-001',
                            'status' => 'published',
                        ],
                    ]],
                ],
            ]),
        ]);

        $this->getJson('/api/search/products?q=scanner&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SCAN-001')
            ->assertJsonPath('meta.query', 'scanner')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'http://elasticsearch:9200/catalog_products/_search'
                && $request['from'] === 0
                && $request['size'] === 10
                && $request['query']['bool']['must'][0]['multi_match']['query'] === 'scanner'
                && $request['query']['bool']['filter'][0]['term']['status'] === 'published';
        });
    }

    public function test_product_search_endpoint_returns_degraded_results_when_elasticsearch_circuit_is_open(): void
    {
        Http::fake();

        $this->app->make(CircuitBreaker::class)->open('elasticsearch');

        $this->getJson('/api/search/products?q=scanner&per_page=10')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.query', 'scanner')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.status', 'degraded')
            ->assertJsonPath('meta.reason', 'elasticsearch_unavailable');

        Http::assertNothingSent();
    }

    public function test_elasticsearch_indexer_fails_fast_when_circuit_is_open(): void
    {
        Http::fake();

        $this->app->make(CircuitBreaker::class)->open('elasticsearch');

        try {
            (new ElasticsearchSearchIndexer)->index('catalog_products', '15', [
                'sku' => 'SCAN-001',
            ]);

            $this->fail('Expected Elasticsearch circuit breaker exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Elasticsearch circuit breaker is open.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_product_search_endpoint_requires_query(): void
    {
        $this->getJson('/api/search/products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_search_product_endpoint_is_declared_in_gateway_and_search_contracts(): void
    {
        foreach ([
            base_path('services/gateway/contracts/openapi.yaml'),
            base_path('services/search/contracts/openapi.yaml'),
        ] as $contract) {
            $contents = file_get_contents($contract);

            $this->assertIsString($contents);
            $this->assertStringContainsString('/api/search/products:', $contents);
            $this->assertStringContainsString("\$ref: '#/components/schemas/SearchProductListResponse'", $contents);
            $this->assertStringContainsString('SearchPaginationMeta:', $contents);
        }
    }

    public function test_elasticsearch_product_search_supports_legacy_total_hits_shape(): void
    {
        Http::fake([
            'http://elasticsearch:9200/catalog_products/_search' => Http::response([
                'hits' => [
                    'total' => 2,
                    'hits' => [],
                ],
            ]),
        ]);

        $results = (new ElasticsearchProductSearch)->search('scanner', 2, 10);

        $this->assertSame(2, $results['meta']['total']);

        Http::assertSent(fn ($request): bool => $request['from'] === 10);
    }
}
