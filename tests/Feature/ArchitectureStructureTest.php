<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArchitectureStructureTest extends TestCase
{
    public function test_it_keeps_the_planned_modular_monolith_directories_in_place(): void
    {
        $directories = [
            'app/Application/Commands',
            'app/Application/DTO',
            'app/Application/Queries',
            'app/Domains/Catalog',
            'app/Domains/Inventory',
            'app/Domains/Orders',
            'app/Domains/Pricing',
            'app/Domains/Search',
            'app/Infrastructure/Cache',
            'app/Infrastructure/Messaging',
            'app/Infrastructure/Persistence',
            'app/Infrastructure/Search',
            'app/Interfaces/Console',
            'app/Interfaces/Http',
        ];

        foreach ($directories as $directory) {
            $this->assertDirectoryExists(base_path($directory));
        }
    }
}
