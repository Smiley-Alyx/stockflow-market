<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Read\CatalogReadService;
use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request, CatalogProductSearch $catalog): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'max:255'],
            'q' => ['sometimes', 'string', 'min:1', 'max:255'],
            'brands' => ['sometimes', 'array'],
            'brands.*' => ['string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['array'],
            'filters.*.*' => ['string', 'max:255'],
            'in_stock' => ['sometimes', 'boolean'],
            'city_code' => ['sometimes', 'string', 'max:64'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'rating_asc', 'rating_desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([12, 16, 20, 24])],
        ]);

        $products = $catalog->products(new CatalogProductQuery(
            query: $filters['q'] ?? null,
            category: $filters['category'] ?? null,
            filters: $filters['filters'] ?? [],
            brands: $filters['brands'] ?? [],
            inStock: array_key_exists('in_stock', $filters) ? (bool) $filters['in_stock'] : null,
            cityCode: isset($filters['city_code']) ? strtolower($filters['city_code']) : null,
            sort: $filters['sort'] ?? 'newest',
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['per_page'] ?? 20),
        ));

        return response()->json($products);
    }

    public function show(string $slug, CatalogReadService $catalog): JsonResponse
    {
        $product = $catalog->productBySlug($slug);

        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $product]);
    }
}
