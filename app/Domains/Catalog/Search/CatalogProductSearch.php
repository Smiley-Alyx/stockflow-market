<?php

namespace App\Domains\Catalog\Search;

interface CatalogProductSearch
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function products(CatalogProductQuery $query): array;
}
