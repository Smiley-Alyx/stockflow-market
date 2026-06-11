<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssistantProductController extends Controller
{
    public function __invoke(Request $request, CatalogProductSearch $catalog): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:255'],
            'color' => ['sometimes', 'string', 'min:2', 'max:64'],
            'in_stock' => ['sometimes', 'boolean'],
            'price_to' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'rating_desc'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($catalog->products(new CatalogProductQuery(
            query: $filters['q'] ?? null,
            category: null,
            categoryPath: null,
            filters: [],
            brands: [],
            inStock: array_key_exists('in_stock', $filters) ? (bool) $filters['in_stock'] : null,
            cityCode: null,
            priceFrom: null,
            priceTo: isset($filters['price_to']) ? (int) $filters['price_to'] : null,
            sort: $filters['sort'] ?? 'newest',
            page: 1,
            perPage: (int) ($filters['limit'] ?? 50),
            color: $filters['color'] ?? null,
        )));
    }
}
