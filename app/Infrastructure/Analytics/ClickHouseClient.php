<?php

namespace App\Infrastructure\Analytics;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ClickHouseClient
{
    public function enabled(): bool
    {
        return (bool) config('stockflow.dependencies.clickhouse.enabled');
    }

    public function query(string $query, ?string $body = null): Response
    {
        $request = Http::timeout((float) config('stockflow.runtime.dependency_timeout_seconds'))
            ->withBasicAuth(
                (string) config('stockflow.dependencies.clickhouse.username'),
                (string) config('stockflow.dependencies.clickhouse.password'),
            );

        if ($body !== null) {
            $request = $request->withBody($body, 'application/x-ndjson');
        }

        return $request
            ->post(rtrim((string) config('stockflow.dependencies.clickhouse.host'), '/').'/?'.http_build_query([
                'database' => (string) config('stockflow.dependencies.clickhouse.database'),
                'query' => $query,
            ]))
            ->throw();
    }
}
