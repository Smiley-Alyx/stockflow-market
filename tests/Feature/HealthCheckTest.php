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
                    'database' => ['ok' => true, 'critical' => true, 'status' => 'ok'],
                    'redis' => ['ok' => true, 'critical' => true, 'status' => 'ok'],
                    'rabbitmq' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                    'elasticsearch' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                    'clickhouse' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.elasticsearch.ok', true)
            ->assertJsonPath('checks.clickhouse.ok', true);
    }

    public function test_readiness_probe_returns_degraded_when_optional_dependency_fails(): void
    {
        $this->app->bind(DependencyHealthChecker::class, fn () => new class extends DependencyHealthChecker
        {
            /**
             * @return array<string, array{ok: bool, critical: bool, status: string, detail?: string}>
             */
            public function readiness(): array
            {
                return [
                    'database' => ['ok' => true, 'critical' => true, 'status' => 'ok'],
                    'redis' => ['ok' => true, 'critical' => true, 'status' => 'ok'],
                    'rabbitmq' => ['ok' => false, 'critical' => false, 'status' => 'degraded', 'detail' => 'connection refused'],
                    'elasticsearch' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                    'clickhouse' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.rabbitmq.ok', false)
            ->assertJsonPath('checks.rabbitmq.critical', false);
    }

    public function test_readiness_probe_returns_unavailable_when_critical_dependency_fails(): void
    {
        $this->app->bind(DependencyHealthChecker::class, fn () => new class extends DependencyHealthChecker
        {
            /**
             * @return array<string, array{ok: bool, critical: bool, status: string, detail?: string}>
             */
            public function readiness(): array
            {
                return [
                    'database' => ['ok' => true, 'critical' => true, 'status' => 'ok'],
                    'redis' => ['ok' => false, 'critical' => true, 'status' => 'unavailable', 'detail' => 'connection refused'],
                    'rabbitmq' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                    'elasticsearch' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                    'clickhouse' => ['ok' => true, 'critical' => false, 'status' => 'ok'],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.redis.ok', false)
            ->assertJsonPath('checks.redis.critical', true);
    }
}
