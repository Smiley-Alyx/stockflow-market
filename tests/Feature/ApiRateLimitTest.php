<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_catalog_products_endpoint_applies_catalog_rate_limit(): void
    {
        config([
            'stockflow.rate_limits.catalog.max_attempts' => 1,
            'stockflow.rate_limits.catalog.decay_seconds' => 60,
        ]);

        $this->getJson('/api/catalog/products')
            ->assertOk();

        $this->getJson('/api/catalog/products')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please retry later.');
    }

    public function test_search_products_endpoint_applies_search_rate_limit(): void
    {
        config([
            'stockflow.rate_limits.search.max_attempts' => 1,
            'stockflow.rate_limits.search.decay_seconds' => 60,
        ]);

        $this->getJson('/api/search/products')
            ->assertUnprocessable();

        $this->getJson('/api/search/products')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please retry later.');
    }

    public function test_order_confirm_endpoint_applies_checkout_rate_limit(): void
    {
        config([
            'stockflow.rate_limits.checkout.max_attempts' => 1,
            'stockflow.rate_limits.checkout.decay_seconds' => 60,
        ]);

        $this->postJson('/api/orders/1/confirm')
            ->assertNotFound();

        $this->postJson('/api/orders/1/confirm')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please retry later.');
    }

    public function test_checkout_search_and_catalog_limits_are_separate(): void
    {
        config([
            'stockflow.rate_limits.checkout.max_attempts' => 1,
            'stockflow.rate_limits.checkout.decay_seconds' => 60,
            'stockflow.rate_limits.search.max_attempts' => 1,
            'stockflow.rate_limits.search.decay_seconds' => 60,
            'stockflow.rate_limits.catalog.max_attempts' => 1,
            'stockflow.rate_limits.catalog.decay_seconds' => 60,
        ]);

        $this->postJson('/api/orders/1/confirm')
            ->assertNotFound();

        $this->getJson('/api/search/products')
            ->assertUnprocessable();

        $this->getJson('/api/catalog/products')
            ->assertOk();

        $this->postJson('/api/orders/1/confirm')
            ->assertTooManyRequests();
        $this->getJson('/api/search/products')
            ->assertTooManyRequests();
        $this->getJson('/api/catalog/products')
            ->assertTooManyRequests();
    }
}
