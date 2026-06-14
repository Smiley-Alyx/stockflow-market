<?php

namespace Tests\Feature;

use Tests\TestCase;

class FaultInjectionTest extends TestCase
{
    public function test_latency_fault_injection_is_disabled_by_default(): void
    {
        $this->getJson('/debug/observability/latency/1000')->assertNotFound();
    }

    public function test_latency_fault_injection_clamps_requested_delay(): void
    {
        config(['stockflow.observability.fault_injection_enabled' => true]);

        $this->getJson('/debug/observability/latency/1')
            ->assertOk()
            ->assertJson([
                'fault' => 'latency',
                'milliseconds' => 1000,
            ]);
    }
}
