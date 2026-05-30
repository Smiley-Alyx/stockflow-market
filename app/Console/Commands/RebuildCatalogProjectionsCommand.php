<?php

namespace App\Console\Commands;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildCatalogProjectionsCommand extends Command
{
    protected $signature = 'catalog:projections:rebuild {--chunk=100 : Products to rebuild per batch}';

    protected $description = 'Rebuild denormalized catalog product projections.';

    public function handle(CatalogProjectionService $projections): int
    {
        $rebuilt = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        Product::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $products) use ($projections, &$rebuilt): void {
                foreach ($products as $product) {
                    $projections->syncProduct($product->id);
                    $rebuilt++;
                }
            });

        CatalogCacheKeys::invalidateProducts();

        $this->info("Rebuilt {$rebuilt} catalog product projection(s).");

        return self::SUCCESS;
    }
}
