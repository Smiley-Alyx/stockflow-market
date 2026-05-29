<?php

namespace App\Domains\Catalog\Listeners;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Inventory\Events\StockChanged;

class InvalidateCatalogProductsOnStockChanged
{
    public function handle(StockChanged $event): void
    {
        CatalogCacheKeys::invalidateProducts();
    }
}
