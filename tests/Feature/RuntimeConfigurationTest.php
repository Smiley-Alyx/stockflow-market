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
        $this->assertSame('rabbitmq', config('stockflow.dependencies.rabbitmq.host'));
        $this->assertSame('http://elasticsearch:9200', config('stockflow.dependencies.elasticsearch.host'));
        $this->assertSame(300, config('stockflow.catalog.cache.product_ttl_seconds'));
        $this->assertSame('search-indexing', config('stockflow.search.indexing.queue'));
        $this->assertSame('search-indexing-dead-letter', config('stockflow.search.indexing.dead_letter_queue'));
        $this->assertSame('redis', config('stockflow.search.indexing.dead_letter_backend'));
        $this->assertSame('search_requeue_audit', config('stockflow.search.indexing.requeue_audit_channel'));
        $this->assertSame(100, config('stockflow.search.indexing.batch_size'));
        $this->assertSame(500, config('stockflow.search.indexing.max_in_flight'));
        $this->assertSame('rabbitmq', config('stockflow.messaging.event_bus'));
        $this->assertSame(5, config('stockflow.messaging.retry.max_attempts'));
    }

    public function test_env_example_documents_operational_overrides(): void
    {
        $envExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('STOCKFLOW_REQUEST_TIMEOUT_MS=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_DEPENDENCY_TIMEOUT_SECONDS=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_MAX_IN_FLIGHT=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_QUEUE=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_BACKEND=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_SEARCH_REQUEUE_AUDIT_CHANNEL=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_MESSAGE_RETRY_ATTEMPTS=', $envExample);
        $this->assertStringContainsString('STOCKFLOW_MESSAGE_DEAD_LETTER_AFTER=', $envExample);
    }
}
