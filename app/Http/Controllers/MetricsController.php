<?php

namespace App\Http\Controllers;

use App\Infrastructure\Observability\PrometheusExporter;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __invoke(PrometheusExporter $exporter): Response
    {
        return response($exporter->export(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
