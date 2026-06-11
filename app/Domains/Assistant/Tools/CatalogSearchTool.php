<?php

namespace App\Domains\Assistant\Tools;

use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;

class CatalogSearchTool
{
    public function __construct(
        private readonly CatalogProductSearch $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    public function execute(array $arguments): array
    {
        $result = $this->catalog->products(new CatalogProductQuery(
            query: $this->nullableString($arguments['q'] ?? null),
            category: null,
            categoryPath: null,
            filters: [],
            brands: [],
            inStock: is_bool($arguments['in_stock'] ?? null) ? $arguments['in_stock'] : null,
            cityCode: null,
            priceFrom: null,
            priceTo: is_numeric($arguments['max_price'] ?? null)
                ? max(0, (int) round((float) $arguments['max_price'] * 100))
                : null,
            sort: in_array($arguments['sort'] ?? null, ['newest', 'price_asc', 'price_desc', 'rating_desc'], true)
                ? $arguments['sort']
                : 'rating_desc',
            page: 1,
            perPage: 10,
            color: $this->nullableString($arguments['color'] ?? null),
        ));

        return array_slice($result['data'], 0, 10);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    public function context(array $products): array
    {
        return collect($products)->map(fn (array $product): array => [
            'id' => $product['id'] ?? null,
            'name' => $product['name'] ?? null,
            'category' => data_get($product, 'category.name'),
            'brand' => data_get($product, 'brand.name'),
            'description' => $product['short_description'] ?? $product['description'] ?? null,
            'price' => $product['price'] ?? null,
            'availability' => $product['availability'] ?? null,
            'rating' => $product['rating'] ?? null,
            'attributes' => $product['attributes'] ?? [],
            'url' => $product['url'] ?? null,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => [
                    'type' => ['string', 'null'],
                    'description' => 'Short product search phrase in Russian without budget or filler words.',
                ],
                'color' => [
                    'type' => ['string', 'null'],
                    'description' => 'A short color fragment, for example синий or графит.',
                ],
                'max_price' => [
                    'type' => ['number', 'null'],
                    'description' => 'Maximum price in the catalog currency units.',
                ],
                'in_stock' => [
                    'type' => ['boolean', 'null'],
                    'description' => 'Whether to require current stock.',
                ],
                'sort' => [
                    'type' => ['string', 'null'],
                    'enum' => ['newest', 'price_asc', 'price_desc', 'rating_desc', null],
                ],
            ],
            'required' => ['q', 'color', 'max_price', 'in_stock', 'sort'],
            'additionalProperties' => false,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
