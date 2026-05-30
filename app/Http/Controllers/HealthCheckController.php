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
        $criticalFailure = collect($checks)->contains(
            fn (array $check): bool => ($check['critical'] ?? true) && ! $check['ok']
        );
        $degraded = collect($checks)->contains(fn (array $check): bool => ! $check['ok']);

        return response()->json([
            'status' => $criticalFailure ? 'unavailable' : ($degraded ? 'degraded' : 'ok'),
            'service' => config('stockflow.runtime.service_name'),
            'checks' => $checks,
        ], $criticalFailure ? 503 : 200);
    }
}
