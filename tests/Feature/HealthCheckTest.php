<?php

namespace Tests\Feature;

use App\Infrastructure\Health\DependencyHealthChecker;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_liveness_probe_reports_runtime_status(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'gateway',
            ]);
    }

    public function test_readiness_probe_reports_dependency_status(): void
    {
        $this->app->bind(DependencyHealthChecker::class, fn () => new class extends DependencyHealthChecker
        {
            /**
             * @return array<string, array{ok: bool, detail?: string}>
             */
            public function readiness(): array
            {
                return [
                    'database' => ['ok' => true],
                    'redis' => ['ok' => true],
                    'rabbitmq' => ['ok' => true],
                    'elasticsearch' => ['ok' => true],
                    'clickhouse' => ['ok' => true],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.elasticsearch.ok', true)
            ->assertJsonPath('checks.clickhouse.ok', true);
    }

    public function test_readiness_probe_returns_unavailable_when_dependency_fails(): void
    {
        $this->app->bind(DependencyHealthChecker::class, fn () => new class extends DependencyHealthChecker
        {
            /**
             * @return array<string, array{ok: bool, detail?: string}>
             */
            public function readiness(): array
            {
                return [
                    'database' => ['ok' => true],
                    'redis' => ['ok' => false, 'detail' => 'connection refused'],
                    'rabbitmq' => ['ok' => true],
                    'elasticsearch' => ['ok' => true],
                    'clickhouse' => ['ok' => true],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.redis.ok', false);
    }
}
