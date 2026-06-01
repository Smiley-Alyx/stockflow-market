<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Search\Jobs\IndexSearchDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RebuildSearchIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_command_queues_all_catalog_products_for_elasticsearch(): void
    {
        Queue::fake();

        $category = Category::query()->create([
            'name' => 'Audio',
            'slug' => 'audio',
        ]);
        $headphones = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Wireless Headphones',
            'slug' => 'wireless-headphones',
            'sku' => 'AUDIO-001',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Portable Speaker',
            'slug' => 'portable-speaker',
            'sku' => 'AUDIO-002',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->artisan('search:index:rebuild --chunk=1')
            ->expectsOutput('Queued 2 catalog search document(s).')
            ->assertSuccessful();

        Queue::assertPushed(IndexSearchDocument::class, 2);
        Queue::assertPushed(IndexSearchDocument::class, function (IndexSearchDocument $job) use ($headphones): bool {
            return $job->index === 'catalog_products'
                && $job->documentId === (string) $headphones->id
                && $job->document['slug'] === 'wireless-headphones';
        });
    }
}
