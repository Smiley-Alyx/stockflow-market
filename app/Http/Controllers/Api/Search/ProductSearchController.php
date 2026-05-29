<?php

namespace App\Http\Controllers\Api\Search;

use App\Domains\Search\Contracts\ProductSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __invoke(Request $request, ProductSearch $search): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($search->search(
            query: $filters['q'],
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['per_page'] ?? 20),
        ));
    }
}
