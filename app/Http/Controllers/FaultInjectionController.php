<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FaultInjectionController extends Controller
{
    public function latency(int $milliseconds): JsonResponse
    {
        if (! config('stockflow.observability.fault_injection_enabled')) {
            throw new NotFoundHttpException;
        }

        $milliseconds = max(1000, min(5000, $milliseconds));
        usleep($milliseconds * 1000);

        return response()->json([
            'fault' => 'latency',
            'milliseconds' => $milliseconds,
        ]);
    }
}
