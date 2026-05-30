<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Read\InventoryReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StockController extends Controller
{
    public function show(Request $request, InventoryReadService $inventory): JsonResponse
    {
        $filters = $request->validate([
            'sku' => ['required_without:product_id', 'string', 'max:255'],
            'product_id' => ['required_without:sku', 'integer', 'min:1'],
            'city_code' => ['sometimes', 'string', 'max:255'],
        ]);

        $stock = $inventory->stock(
            sku: $filters['sku'] ?? null,
            productId: isset($filters['product_id']) ? (int) $filters['product_id'] : null,
            cityCode: $filters['city_code'] ?? null,
        );

        if ($stock === null) {
            return response()->json(['message' => 'Stock not found'], 404);
        }

        return response()->json(['data' => $stock]);
    }

    public function movements(Request $request, InventoryReadService $inventory): JsonResponse
    {
        $filters = $request->validate([
            'stock_item_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string', Rule::in(['received', 'reserved', 'released', 'expired', 'deducted', 'returned'])],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $movements = $inventory->movements(
                stockItemId: isset($filters['stock_item_id']) ? (int) $filters['stock_item_id'] : null,
                type: $filters['type'] ?? null,
                cursor: $filters['cursor'] ?? null,
                perPage: isset($filters['per_page']) ? (int) $filters['per_page'] : 50,
            );
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid movement cursor.'], 422);
        }

        return response()->json($movements);
    }
}
