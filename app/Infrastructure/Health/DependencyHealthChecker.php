<?php

namespace App\Infrastructure\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DependencyHealthChecker
{
    /**
     * @return array<string, array{ok: bool, detail?: string}>
     */
    public function readiness(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'rabbitmq' => $this->tcpService(
                (string) config('stockflow.dependencies.rabbitmq.host'),
                (int) config('stockflow.dependencies.rabbitmq.port'),
            ),
            'elasticsearch' => $this->elasticsearch(),
        ];
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
}
