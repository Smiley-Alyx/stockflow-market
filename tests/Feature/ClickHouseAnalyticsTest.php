<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Listeners\ProjectStockMovementAnalytics;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Infrastructure\Analytics\StockMovementAnalyticsProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClickHouseAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initializes_the_stock_movement_projection(): void
    {
        Http::fake();

        $this->app->make(StockMovementAnalyticsProjection::class)->initialize();

        Http::assertSent(function (Request $request): bool {
            $query = $this->requestQuery($request);

            return str_contains($query, 'CREATE TABLE IF NOT EXISTS inventory_stock_movements')
                && str_contains($query, 'ReplacingMergeTree(ingested_at)')
                && str_contains($query, 'PARTITION BY toYYYYMM(occurred_at)');
        });
    }

    public function test_it_projects_a_stock_changed_event_once(): void
    {
        config()->set('stockflow.dependencies.clickhouse.enabled', true);
        Http::fake();

        $stockItem = $this->createStockItem();
        $movement = StockMovement::query()->create([
            'stock_item_id' => $stockItem->id,
            'type' => StockMovement::TYPE_RECEIVED,
            'quantity' => 4,
            'reference_type' => 'shipment',
            'reference_id' => 'SHIP-1',
            'metadata' => ['source' => 'erp'],
            'occurred_at' => now(),
        ]);
        $listener = $this->app->make(ProjectStockMovementAnalytics::class);
        $event = new StockChanged($stockItem, $movement);

        $listener->handle($event);
        $listener->handle($event);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($movement, $stockItem): bool {
            $row = json_decode(trim($request->body()), true, 512, JSON_THROW_ON_ERROR);

            return $this->requestQuery($request) === 'INSERT INTO inventory_stock_movements FORMAT JSONEachRow'
                && $row['movement_id'] === $movement->id
                && $row['stock_item_id'] === $stockItem->id
                && $row['movement_type'] === StockMovement::TYPE_RECEIVED
                && $row['metadata'] === '{"source":"erp"}';
        });
    }

    public function test_it_rebuilds_hot_and_archived_stock_movements(): void
    {
        config()->set('stockflow.dependencies.clickhouse.enabled', true);
        Http::fake();

        $stockItem = $this->createStockItem();
        $hot = StockMovement::query()->create([
            'stock_item_id' => $stockItem->id,
            'type' => StockMovement::TYPE_RECEIVED,
            'quantity' => 4,
            'occurred_at' => now()->subDay(),
        ]);
        $archived = StockMovement::query()->create([
            'stock_item_id' => $stockItem->id,
            'type' => StockMovement::TYPE_DEDUCTED,
            'quantity' => 2,
            'occurred_at' => now()->subDays(200),
        ]);

        $this->artisan('inventory:stock-movements:archive --days=180')
            ->assertExitCode(0);

        $this->artisan('analytics:stock-movements:rebuild --batch-size=1')
            ->expectsOutput('Projected 2 inventory stock movement(s) into ClickHouse.')
            ->assertExitCode(0);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $this->requestQuery($request) === 'TRUNCATE TABLE inventory_stock_movements');
        Http::assertSent(function (Request $request) use ($hot): bool {
            return $this->requestQuery($request) === 'INSERT INTO inventory_stock_movements FORMAT JSONEachRow'
                && str_contains($request->body(), '"movement_id":'.$hot->id);
        });
        Http::assertSent(function (Request $request) use ($archived): bool {
            return $this->requestQuery($request) === 'INSERT INTO inventory_stock_movements FORMAT JSONEachRow'
                && str_contains($request->body(), '"movement_id":'.$archived->id);
        });
    }

    public function test_rebuild_command_rejects_disabled_clickhouse(): void
    {
        Http::fake();

        $this->artisan('analytics:stock-movements:rebuild')
            ->expectsOutput('ClickHouse analytics is disabled. Set CLICKHOUSE_ENABLED=true.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function requestQuery(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return (string) ($parameters['query'] ?? '');
    }

    private function createStockItem(): StockItem
    {
        $category = Category::query()->create([
            'name' => 'Devices',
            'slug' => 'devices',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Wireless Scanner',
            'slug' => 'wireless-scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WAW',
            'name' => 'Warsaw Warehouse',
            'city_code' => 'waw',
            'city_name' => 'Warsaw',
            'is_active' => true,
        ]);

        return StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 10,
            'reserved_quantity' => 0,
        ]);
    }
}
