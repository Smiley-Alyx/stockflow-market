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
}
