<?php

namespace Tests\Feature;

use Tests\TestCase;

class RuntimeConfigurationTest extends TestCase
{
    public function test_it_exposes_highload_runtime_defaults(): void
    {
        $this->assertSame('gateway', config('stockflow.runtime.service_name'));
        $this->assertSame(2500, config('stockflow.runtime.request_timeout_ms'));
        $this->assertSame(2, config('stockflow.runtime.dependency_timeout_seconds'));
        $this->assertSame(15, config('stockflow.runtime.shutdown_timeout_seconds'));
        $this->assertFalse(config('stockflow.dependencies.rabbitmq.enabled'));
        $this->assertSame('rabbitmq', config('stockflow.dependencies.rabbitmq.host'));
        $this->assertSame('stockflow', config('stockflow.dependencies.rabbitmq.user'));
        $this->assertSame('http://elasticsearch:9200', config('stockflow.dependencies.elasticsearch.host'));
        $this->assertSame('http://clickhouse:8123', config('stockflow.dependencies.clickhouse.host'));
        $this->assertFalse(config('stockflow.dependencies.clickhouse.enabled'));
        $this->assertSame(9000, config('stockflow.dependencies.clickhouse.native_port'));
        $this->assertSame('stockflow', config('stockflow.dependencies.clickhouse.database'));
        $this->assertSame(1000, config('stockflow.analytics.stock_movements.rebuild_batch_size'));
        $this->assertSame(300, config('stockflow.catalog.cache.product_ttl_seconds'));
        $this->assertSame('search-indexing', config('stockflow.search.indexing.queue'));
        $this->assertSame(30000, config('stockflow.search.indexing.bulk_timeout_ms'));
        $this->assertSame('search-indexing-dead-letter', config('stockflow.search.indexing.dead_letter_queue'));
        $this->assertSame('redis', config('stockflow.search.indexing.dead_letter_backend'));
        $this->assertSame('search_requeue_audit', config('stockflow.search.indexing.requeue_audit_channel'));
        $this->assertSame(100, config('stockflow.search.indexing.requeue_batch_size'));
        $this->assertSame(500, config('stockflow.search.indexing.max_requeue_batch_size'));
        $this->assertSame(100, config('stockflow.search.indexing.batch_size'));
        $this->assertSame(500, config('stockflow.search.indexing.max_in_flight'));
        $this->assertSame('in_process', config('stockflow.messaging.event_bus'));
        $this->assertSame('stockflow.domain.events', config('stockflow.messaging.rabbitmq.exchange'));
        $this->assertSame('stockflow.market.domain.events', config('stockflow.messaging.rabbitmq.queue'));
        $this->assertSame(5, config('stockflow.messaging.retry.max_attempts'));
    }

    public function test_env_example_documents_operational_overrides(): void
    {
        $envExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('STOCKFLOW_REQUEST_TIMEOUT_MS=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_DEPENDENCY_TIMEOUT_SECONDS=', $envExample);
        $this->assertStringContainsString('RABBITMQ_ENABLED=false', $envExample);
        $this->assertStringContainsString('CLICKHOUSE_HOST=', $envExample);
        $this->assertStringContainsString('CLICKHOUSE_ENABLED=false', $envExample);
        $this->assertStringContainsString('CLICKHOUSE_NATIVE_PORT=', $envExample);
        $this->assertStringContainsString('CLICKHOUSE_DATABASE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_ANALYTICS_STOCK_MOVEMENT_REBUILD_BATCH_SIZE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_MAX_IN_FLIGHT=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_QUEUE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_BACKEND=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_REQUEUE_AUDIT_CHANNEL=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_REQUEUE_BATCH_SIZE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_EVENT_BUS=in_process', $envExample);
        $this->assertStringContainsString('STOCKFLOW_DOMAIN_EVENTS_QUEUE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_DOMAIN_EVENTS_DLQ=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_MESSAGE_RETRY_ATTEMPTS=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_MESSAGE_DEAD_LETTER_AFTER=', $envExample);
    }
}
