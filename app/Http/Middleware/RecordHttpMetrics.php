<?php

namespace App\Http\Middleware;

use App\Infrastructure\Observability\MetricsCollector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordHttpMetrics
{
    public function __construct(private readonly MetricsCollector $metrics) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        if (! $request->is('metrics')) {
            $this->metrics->observeHttpLatency(
                $request->method(),
                $this->endpoint($request),
                $response->getStatusCode(),
                microtime(true) - $startedAt,
            );
        }

        return $response;
    }

    private function endpoint(Request $request): string
    {
        $route = $request->route();

        if ($route !== null && method_exists($route, 'uri')) {
            return '/'.$route->uri();
        }

        return '/'.$request->path();
    }
}
