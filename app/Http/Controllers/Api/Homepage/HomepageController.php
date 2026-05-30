<?php

namespace App\Http\Controllers\Api\Homepage;

use App\Domains\Homepage\Read\HomepageReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HomepageController extends Controller
{
    public function __invoke(HomepageReadService $homepage): JsonResponse
    {
        return response()->json(['data' => $homepage->blocks()]);
    }
}
