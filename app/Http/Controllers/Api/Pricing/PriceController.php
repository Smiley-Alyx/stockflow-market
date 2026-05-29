<?php

namespace App\Http\Controllers\Api\Pricing;

use App\Domains\Pricing\Read\PricingReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceController extends Controller
{
    public function index(Request $request, PricingReadService $pricing): JsonResponse
    {
        $filters = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['integer', 'min:1'],
            'price_types' => ['sometimes', 'array', 'min:1', 'max:20'],
            'price_types.*' => ['string', 'max:64'],
        ]);

        return response()->json([
            'data' => $pricing->pricesForProductIds($filters['product_ids'], $filters['price_types'] ?? []),
        ]);
    }
}
