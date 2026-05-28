<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domains\Catalog\Read\CatalogReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function tree(CatalogReadService $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->activeCategoryTree()]);
    }
}
