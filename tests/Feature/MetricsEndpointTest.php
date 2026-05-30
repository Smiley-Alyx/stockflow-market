<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Search\DeadLetters\ArraySearchIndexDeadLetterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        ArraySearchIndexDeadLetterStore::reset();
        $this->app->bind(SearchIndexDeadLetterStore::class, ArraySearchIndexDeadLetterStore::class);
    }

    public function test_metrics_endpoint_exports_http_latency_and_operational_gauges(): void
    {
        $this->getJson('/health/live')->assertOk();

        $this->get('/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->assertSee('stockflow_http_request_duration_seconds_bucket{endpoint="/health/live",le="+Inf",method="GET",status="200"}', false)
            ->assertSee('stockflow_queue_depth{queue="default"} 0', false)
            ->assertSee('stockflow_search_dead_letter_count{queue="search-indexing-dead-letter"} 0', false);
    }

    public function test_metrics_endpoint_exports_search_indexing_failures_and_dead_letter_count(): void
    {
        Event::fake();

        $job = new IndexSearchDocument('catalog_products', '15', ['id' => 15]);
        $job->failed(new RuntimeException('Elasticsearch is unavailable.'));

        $this->get('/metrics')
            ->assertOk()
            ->assertSee('stockflow_search_indexing_failures_total{index="catalog_products",operation="index"} 1', false)
            ->assertSee('stockflow_search_dead_letter_count{queue="search-indexing-dead-letter"} 1', false);
    }

    public function test_reservation_conflicts_are_exported(): void
    {
        $product = Product::query()->create([
            'category_id' => Category::query()->create(['name' => 'Scanners', 'slug' => 'scanners'])->id,
            'name' => 'Scanner',
            'slug' => 'scanner',
            'sku' => 'SCAN-001',
            'description' => 'Handheld scanner',
            'status' => 'active',
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'WAW',
            'name' => 'Warsaw warehouse',
            'city_code' => 'waw',
            'city_name' => 'Warsaw',
            'is_active' => true,
        ]);

        $stockItem = StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 1,
            'reserved_quantity' => 0,
        ]);

        $this->postJson('/api/inventory/reservations', [
            'stock_item_id' => $stockItem->id,
            'quantity' => 2,
            'reservation_expires_at' => now()->addMinutes(10)->toJSON(),
        ], [
            'Idempotency-Key' => 'order-conflict-1',
        ])->assertStatus(409);

        $this->get('/metrics')
            ->assertOk()
            ->assertSee('stockflow_inventory_reservation_conflicts_total{reason="insufficient_stock"} 1', false);
    }
}
