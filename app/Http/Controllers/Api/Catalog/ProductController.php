<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Read\ProductCardReadService;
use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use App\Domains\Catalog\Services\CatalogUrlService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request, CatalogProductSearch $catalog): JsonResponse
    {
        return response()->json($catalog->products($this->productQuery($this->validatedFilters($request))));
    }

    public function path(
        string $path,
        Request $request,
        CatalogProductSearch $catalog,
        ProductCardReadService $cards,
        CatalogUrlService $urls,
    ): JsonResponse {
        $productPath = $urls->parseProductPath($path);

        if ($productPath !== null) {
            $product = $cards->productByPath($productPath['category_path'], $productPath['product_slug']);

            if ($product !== null) {
                return response()->json(['data' => $product]);
            }
        }

        $filters = array_merge($this->validatedFilters($request), $urls->parseCatalogPath($path));

        return response()->json($catalog->products($this->productQuery($filters)));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'category' => ['sometimes', 'string', 'max:255'],
            'category_path' => ['sometimes', 'string', 'max:767'],
            'q' => ['sometimes', 'string', 'min:1', 'max:255'],
            'brands' => ['sometimes', 'array'],
            'brands.*' => ['string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['array'],
            'filters.*.*' => ['string', 'max:255'],
            'in_stock' => ['sometimes', 'boolean'],
            'city_code' => ['sometimes', 'string', 'max:64'],
            'price_from' => ['sometimes', 'integer', 'min:0'],
            'price_to' => ['sometimes', 'integer', 'min:0', 'gte:price_from'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'rating_asc', 'rating_desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([12, 16, 20, 24])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function productQuery(array $filters): CatalogProductQuery
    {
        return new CatalogProductQuery(
            query: $filters['q'] ?? null,
            category: $filters['category'] ?? null,
            categoryPath: $filters['category_path'] ?? null,
            filters: $filters['filters'] ?? [],
            brands: $filters['brands'] ?? [],
            inStock: array_key_exists('in_stock', $filters) ? (bool) $filters['in_stock'] : null,
            cityCode: isset($filters['city_code']) ? strtolower($filters['city_code']) : null,
            priceFrom: isset($filters['price_from']) ? (int) $filters['price_from'] : null,
            priceTo: isset($filters['price_to']) ? (int) $filters['price_to'] : null,
            sort: $filters['sort'] ?? 'newest',
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['per_page'] ?? 20),
        );
    }

    public function show(string $slug, ProductCardReadService $catalog): JsonResponse
    {
        $product = $catalog->productBySlug($slug);

        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $product]);
    }
}
