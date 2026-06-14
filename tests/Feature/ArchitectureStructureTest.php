<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArchitectureStructureTest extends TestCase
{
    public function test_it_keeps_the_planned_microservice_directories_in_place(): void
    {
        $directories = [
            'docs/adr',
            'services/gateway/contracts',
            'services/gateway/src',
            'services/gateway/tests',
            'services/catalog/contracts',
            'services/catalog/database/migrations',
            'services/catalog/messaging',
            'services/catalog/src',
            'services/catalog/tests',
            'services/inventory/contracts',
            'services/inventory/database/migrations',
            'services/inventory/messaging',
            'services/inventory/src',
            'services/inventory/tests',
            'services/orders/contracts',
            'services/orders/database/migrations',
            'services/orders/messaging',
            'services/orders/src',
            'services/orders/tests',
            'services/pricing/contracts',
            'services/pricing/database/migrations',
            'services/pricing/messaging',
            'services/pricing/src',
            'services/pricing/tests',
            'services/search/contracts',
            'services/search/database/migrations',
            'services/search/messaging',
            'services/search/src',
            'services/search/tests',
        ];

        foreach ($directories as $directory) {
            $this->assertDirectoryExists(base_path($directory));
        }
    }

    public function test_it_documents_the_transitional_gateway_architecture(): void
    {
        $this->assertFileExists(base_path('docs/adr/0001-laravel-gateway-service-workspace.md'));
    }

    public function test_compose_defines_queue_worker_processes(): void
    {
        $compose = (string) file_get_contents(base_path('compose.yaml'));

        $this->assertStringContainsString('queue-worker:', $compose);
        $this->assertStringContainsString('search-index-worker:', $compose);
        $this->assertStringContainsString('php artisan queue:work redis --queue=default', $compose);
        $this->assertStringContainsString('php artisan queue:work redis --queue=search-indexing', $compose);
    }

    public function test_common_compose_routes_domain_events_through_rabbitmq(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose-all.yml'));

        $this->assertStringContainsString('domain-outbox-worker:', $compose);
        $this->assertStringContainsString('STOCKFLOW_EVENT_BUS: rabbitmq', $compose);
        $this->assertStringContainsString('domain-event-worker:', $compose);
        $this->assertStringContainsString('php artisan messaging:domain-events:consume', $compose);
    }

    public function test_prometheus_loads_operational_alert_rules_and_rabbitmq_metrics(): void
    {
        $compose = (string) file_get_contents(base_path('compose.yaml'));
        $prometheus = (string) file_get_contents(base_path('docker/prometheus/prometheus.yml'));
        $rules = (string) file_get_contents(base_path('docker/prometheus/rules/stockflow-alerts.yml'));

        $this->assertFileExists(base_path('docker/prometheus/rules/stockflow-alerts.test.yml'));
        $this->assertStringContainsString('./docker/prometheus/rules:/etc/prometheus/rules:ro', $compose);
        $this->assertStringContainsString('/etc/prometheus/rules/*-alerts.yml', $prometheus);
        $this->assertStringContainsString('/metrics/per-object', $prometheus);
        $this->assertStringContainsString('rabbitmq:15692', $prometheus);
        $this->assertStringContainsString('alert: StockflowDeadLetterGrowth', $rules);
        $this->assertStringContainsString('alert: StockflowQueueBacklogHigh', $rules);
        $this->assertStringContainsString('alert: StockflowHttpLatencyP95High', $rules);
    }

    public function test_frontend_mounts_only_provider_backed_assistant(): void
    {
        $app = (string) file_get_contents(base_path('frontend/app.vue'));

        $this->assertStringContainsString("\nAiAssistantWidget\n", $app);
        $this->assertStringNotContainsString("\nAssistantWidget\n", $app);
        $this->assertFileDoesNotExist(base_path('frontend/components/AssistantWidget.vue'));
    }

    public function test_compose_defines_clickhouse_storage(): void
    {
        $compose = (string) file_get_contents(base_path('compose.yaml'));

        $this->assertStringContainsString('clickhouse:', $compose);
        $this->assertStringContainsString('clickhouse/clickhouse-server:', $compose);
        $this->assertStringContainsString('profiles: ["extended"]', $compose);
        $this->assertSame(2, substr_count($compose, 'profiles: ["extended"]'));
        $this->assertStringContainsString('CLICKHOUSE_DB: stockflow', $compose);
        $this->assertStringContainsString('clickhouse-data:', $compose);
        $this->assertStringContainsString('./docker/clickhouse/initdb:/docker-entrypoint-initdb.d:ro', $compose);
        $this->assertFileExists(base_path('docker/clickhouse/initdb/001_inventory_stock_movements.sql'));
    }
}
