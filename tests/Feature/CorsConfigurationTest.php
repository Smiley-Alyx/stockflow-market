<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_catalog_path_allows_frontend_preflight_requests(): void
    {
        $this->options('/catalog/electronics/audio', [], [
            'HTTP_ORIGIN' => config('cors.allowed_origins.0'),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', config('cors.allowed_origins.0'));
    }
}
