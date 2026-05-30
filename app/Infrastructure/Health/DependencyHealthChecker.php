<?php

namespace App\Infrastructure\Health;

use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DependencyHealthChecker
{
    /**
     * @return array<string, array{ok: bool, critical: bool, status: string, detail?: string}>
     */
    public function readiness(): array
    {
        $checks = [
            'database' => $this->check('database', true, fn (): array => $this->database()),
            'redis' => $this->check('redis', true, fn (): array => $this->redis()),
            'elasticsearch' => $this->check('elasticsearch', (bool) config('stockflow.dependencies.elasticsearch.critical'), fn (): array => $this->elasticsearch()),
        ];

        if (config('stockflow.dependencies.rabbitmq.enabled')) {
            $checks['rabbitmq'] = $this->check('rabbitmq', (bool) config('stockflow.dependencies.rabbitmq.critical'), fn (): array => $this->tcpService(
                (string) config('stockflow.dependencies.rabbitmq.host'),
                (int) config('stockflow.dependencies.rabbitmq.port'),
            ));
        }

        if (config('stockflow.dependencies.clickhouse.enabled')) {
            $checks['clickhouse'] = $this->check('clickhouse', (bool) config('stockflow.dependencies.clickhouse.critical'), fn (): array => $this->clickhouse());
        }

        return $checks;
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function elasticsearch(): array
    {
        try {
            $response = Http::timeout((int) ceil(config('stockflow.search.indexing.timeout_ms') / 1000))
                ->get(rtrim((string) config('stockflow.dependencies.elasticsearch.host'), '/').'/_cluster/health');

            return $response->successful()
                ? ['ok' => true]
                : ['ok' => false, 'detail' => 'HTTP '.$response->status()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function clickhouse(): array
    {
        try {
            $response = Http::timeout((float) config('stockflow.runtime.dependency_timeout_seconds'))
                ->withBasicAuth(
                    (string) config('stockflow.dependencies.clickhouse.username'),
                    (string) config('stockflow.dependencies.clickhouse.password'),
                )
                ->get(rtrim((string) config('stockflow.dependencies.clickhouse.host'), '/').'/ping');

            return $response->successful()
                ? ['ok' => true]
                : ['ok' => false, 'detail' => 'HTTP '.$response->status()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function tcpService(string $host, int $port): array
    {
        $socket = @stream_socket_client(
            "{$host}:{$port}",
            $errorCode,
            $errorMessage,
            (float) config('stockflow.runtime.dependency_timeout_seconds'),
        );

        if ($socket === false) {
            return ['ok' => false, 'detail' => trim("{$errorCode} {$errorMessage}")];
        }

        fclose($socket);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, critical: bool, status: string, detail?: string}
     */
    private function check(string $dependency, bool $critical, callable $probe): array
    {
        /** @var array{ok: bool, detail?: string} $result */
        $result = $probe();

        if (in_array($dependency, ['elasticsearch', 'rabbitmq'], true)) {
            $result['ok']
                ? app(CircuitBreaker::class)->recordSuccess($dependency)
                : app(CircuitBreaker::class)->recordFailure($dependency);
        }

        return [
            ...$result,
            'critical' => $critical,
            'status' => $result['ok'] ? 'ok' : ($critical ? 'unavailable' : 'degraded'),
        ];
    }
}
