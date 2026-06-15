<?php

namespace App\Console\Commands;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearchDocumentFactory;
use App\Domains\Search\Contracts\BulkSearchIndexer;
use App\Domains\Search\Jobs\IndexSearchDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildSearchIndexCommand extends Command
{
    protected $signature = 'search:index:rebuild
        {--chunk=100 : Products to process per batch}
        {--sync : Write documents directly using the Elasticsearch bulk API}';

    protected $description = 'Queue a full Elasticsearch catalog index rebuild.';

    public function handle(CatalogSearchDocumentFactory $documents, BulkSearchIndexer $bulkIndexer): int
    {
        $processed = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');

        Product::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $products) use ($bulkIndexer, $documents, &$processed, $sync): void {
                $batch = [];

                foreach ($products as $product) {
                    $documentId = (string) $product->id;
                    $document = $documents->make($product);

                    if ($sync) {
                        $batch[$documentId] = $document;
                    } else {
                        IndexSearchDocument::dispatch(
                            index: 'catalog_products',
                            documentId: $documentId,
                            document: $document,
                        );
                    }

                    $processed++;
                }

                if ($sync) {
                    $bulkIndexer->indexMany('catalog_products', $batch);
                }
            });

        $verb = $sync ? 'Indexed' : 'Queued';
        $this->info("{$verb} {$processed} catalog search document(s).");

        return self::SUCCESS;
    }
}
