<?php

namespace App\Domains\Catalog\Search;

class CatalogProductQuery
{
    /**
     * @param  array<string, array<int, string>>  $filters
     * @param  array<int, string>  $brands
     */
    public function __construct(
        public readonly ?string $query,
        public readonly ?string $category,
        public readonly ?string $categoryPath,
        public readonly array $filters,
        public readonly array $brands,
        public readonly ?bool $inStock,
        public readonly ?string $cityCode,
        public readonly ?int $priceFrom,
        public readonly ?int $priceTo,
        public readonly string $sort,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $color = null,
    ) {}
}
