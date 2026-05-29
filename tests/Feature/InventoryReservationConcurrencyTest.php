<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\Reservation;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryReservationConcurrencyTest extends TestCase
{
    public function test_concurrent_reserve_requests_do_not_oversell_10_units(): void
    {
        $database = storage_path('framework/testing/inventory-concurrency-'.getmypid().'.sqlite');
        $originalDatabase = config('database.connections.sqlite.database');

        touch($database);

        try {
            Queue::fake();

            config(['database.connections.sqlite.database' => $database]);
            config(['stockflow.search.indexing.dead_letter_backend' => 'array']);
            DB::purge('sqlite');
            Artisan::call('migrate:fresh');

            $stockItemId = $this->createStockItem(onHand: 10)->id;
            DB::disconnect('sqlite');

            $results = $this->runConcurrentReserveAttempts($database, $stockItemId, 100);

            config(['database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');

            $stockItem = StockItem::query()->findOrFail($stockItemId);

            $this->assertSame(10, count(array_filter($results, fn (string $result): bool => $result === 'reserved')));
            $this->assertSame(90, count(array_filter($results, fn (string $result): bool => $result === 'sold_out')));
            $this->assertSame(10, $stockItem->reserved_quantity);
            $this->assertSame(0, $stockItem->availableQuantity());
            $this->assertSame(10, Reservation::query()->where('status', Reservation::STATUS_ACTIVE)->count());
        } finally {
            config(['database.connections.sqlite.database' => $originalDatabase]);
            DB::purge('sqlite');

            if (file_exists($database)) {
                unlink($database);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function runConcurrentReserveAttempts(string $database, int $stockItemId, int $attempts): array
    {
        $code = <<<'PHP'
DB::statement('PRAGMA busy_timeout = 30000');

$stockItem = \App\Domains\Inventory\Models\StockItem::query()->findOrFail((int) getenv('STOCK_ITEM_ID'));
$order = (string) getenv('ORDER_NUMBER');

for ($attempt = 1; $attempt <= 20; $attempt++) {
    try {
        app(\App\Domains\Inventory\Services\InventoryService::class)->reserve(
            $stockItem,
            1,
            'order-'.$order,
            now()->addMinutes(10),
            'order',
            $order,
        );

        echo 'reserved';

        return;
    } catch (\App\Domains\Inventory\Services\InsufficientStock) {
        echo 'sold_out';

        return;
    } catch (\Illuminate\Database\QueryException $exception) {
        if (! str_contains($exception->getMessage(), 'database is locked') || $attempt === 20) {
            throw $exception;
        }

        usleep(random_int(10000, 100000));
    }
}
PHP;

        $processes = [];

        for ($order = 1; $order <= $attempts; $order++) {
            $processes[$order] = $this->startReserveAttemptProcess($database, $stockItemId, $order, $code);
        }

        $results = [];

        foreach ($processes as $order => $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);

            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);

            $exitCode = proc_close($process['resource']);

            $this->assertSame(0, $exitCode, "Reserve process {$order} failed: {$stderr}{$stdout}");

            $results[] = trim($stdout);
        }

        return $results;
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startReserveAttemptProcess(string $database, int $stockItemId, int $order, string $code): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, 'artisan', 'tinker', '--execute', $code],
            $descriptors,
            $pipes,
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_MAINTENANCE_DRIVER' => 'file',
                'BCRYPT_ROUNDS' => '4',
                'BROADCAST_CONNECTION' => 'null',
                'CACHE_STORE' => 'array',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database,
                'DB_URL' => '',
                'MAIL_MAILER' => 'array',
                'ORDER_NUMBER' => (string) $order,
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
                'STOCK_ITEM_ID' => (string) $stockItemId,
                'STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_BACKEND' => 'array',
            ],
        );

        $this->assertIsResource($process);

        return [
            'resource' => $process,
            'pipes' => $pipes,
        ];
    }

    private function createStockItem(int $onHand): StockItem
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
            'description' => 'Compact scanner for warehouse teams.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'WAW',
            'name' => 'WAW Warehouse',
            'is_active' => true,
        ]);

        return StockItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => 0,
        ]);
    }
}
