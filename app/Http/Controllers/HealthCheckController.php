<?php

namespace App\Http\Controllers;

use App\Infrastructure\Health\DependencyHealthChecker;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('stockflow.runtime.service_name'),
        ]);
    }

    public function ready(DependencyHealthChecker $health): JsonResponse
    {
        $checks = $health->readiness();
        $ready = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'service' => config('stockflow.runtime.service_name'),
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
