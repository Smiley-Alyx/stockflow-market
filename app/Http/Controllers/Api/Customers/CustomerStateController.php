<?php

namespace App\Http\Controllers\Api\Customers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Services\CustomerStateService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerStateController extends Controller
{
    public function show(Request $request, CustomerStateService $customers): JsonResponse
    {
        return response()->json(['data' => $customers->state($request->user())]);
    }

    public function merge(Request $request, CustomerStateService $customers): JsonResponse
    {
        $payload = $request->validate([
            'cart_items' => ['sometimes', 'array', 'max:100'],
            'cart_items.*.product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'cart_items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'favorite_product_ids' => ['sometimes', 'array', 'max:100'],
            'favorite_product_ids.*' => ['integer', 'distinct', 'exists:catalog_products,id'],
        ]);

        return response()->json([
            'data' => $customers->merge(
                $request->user(),
                $payload['cart_items'] ?? [],
                $payload['favorite_product_ids'] ?? [],
            ),
        ]);
    }

    public function setCartItem(Product $product, Request $request, CustomerStateService $customers): JsonResponse
    {
        $payload = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json([
            'data' => $customers->setCartItem($request->user(), $product->id, (int) $payload['quantity']),
        ]);
    }

    public function removeCartItem(Product $product, Request $request, CustomerStateService $customers): JsonResponse
    {
        return response()->json([
            'data' => $customers->removeCartItem($request->user(), $product->id),
        ]);
    }

    public function addFavorite(Product $product, Request $request, CustomerStateService $customers): JsonResponse
    {
        return response()->json([
            'data' => $customers->addFavorite($request->user(), $product->id),
        ]);
    }

    public function removeFavorite(Product $product, Request $request, CustomerStateService $customers): JsonResponse
    {
        return response()->json([
            'data' => $customers->removeFavorite($request->user(), $product->id),
        ]);
    }
}
