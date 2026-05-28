<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Read\CatalogReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function show(string $slug, CatalogReadService $catalog): JsonResponse
    {
        $product = $catalog->productBySlug($slug);

        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $product]);
    }
}
