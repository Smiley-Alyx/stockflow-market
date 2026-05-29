<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Read\CatalogReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, CatalogReadService $catalog): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $catalog->productList(
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['per_page'] ?? 20),
            categorySlug: $filters['category'] ?? null,
        );

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
