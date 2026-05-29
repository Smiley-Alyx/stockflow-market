<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Read\InventoryReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function show(Request $request, InventoryReadService $inventory): JsonResponse
    {
        $filters = $request->validate([
            'sku' => ['required_without:product_id', 'string', 'max:255'],
            'product_id' => ['required_without:sku', 'integer', 'min:1'],
        ]);

        $stock = $inventory->stock(
            sku: $filters['sku'] ?? null,
            productId: isset($filters['product_id']) ? (int) $filters['product_id'] : null,
        );

        if ($stock === null) {
            return response()->json(['message' => 'Stock not found'], 404);
        }

        return response()->json(['data' => $stock]);
    }
}
