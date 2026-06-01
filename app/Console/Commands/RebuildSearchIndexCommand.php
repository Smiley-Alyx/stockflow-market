<?php

namespace App\Console\Commands;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearchDocumentFactory;
use App\Domains\Search\Jobs\IndexSearchDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildSearchIndexCommand extends Command
{
    protected $signature = 'search:index:rebuild {--chunk=100 : Products to queue per batch}';

    protected $description = 'Queue a full Elasticsearch catalog index rebuild.';

    public function handle(CatalogSearchDocumentFactory $documents): int
    {
        $queued = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        Product::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $products) use ($documents, &$queued): void {
                foreach ($products as $product) {
                    IndexSearchDocument::dispatch(
                        index: 'catalog_products',
                        documentId: (string) $product->id,
                        document: $documents->make($product),
                    );
                    $queued++;
                }
            });

        $this->info("Queued {$queued} catalog search document(s).");

        return self::SUCCESS;
    }
}
