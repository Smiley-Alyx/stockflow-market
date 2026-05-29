<?php

namespace Tests\Feature;

use App\Domains\Search\Contracts\ProductSearch;
use Tests\Support\AssertsOpenApiContracts;
use Tests\TestCase;

class SearchApiContractTest extends TestCase
{
    use AssertsOpenApiContracts;

    public function test_product_search_endpoint_matches_gateway_and_search_contracts(): void
    {
        $this->app->instance(ProductSearch::class, new class implements ProductSearch
        {
            /**
             * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
             */
            public function search(string $query, int $page, int $perPage): array
            {
                return [
                    'data' => [[
                        'id' => 15,
                        'name' => 'Wireless Scanner',
                        'slug' => 'wireless-scanner',
                        'sku' => 'SCAN-001',
                        'description' => 'Compact scanner for warehouse teams.',
                        'status' => 'published',
                        'category_id' => 7,
                        'published_at' => '2026-05-28T10:15:30+00:00',
                    ]],
                    'meta' => [
                        'query' => $query,
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => 1,
                    ],
                ];
            }
        });

        $payload = $this->getJson('/api/search/products?q=scanner&per_page=10')
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->searchContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/search/products', '200', 'SearchProductListResponse');
            $this->assertSchemaMatchesPayload($contract, 'SearchProductListResponse', $payload);
            $this->assertSchemaMatchesPayload($contract, 'SearchPaginationMeta', $payload['meta']);
            $this->assertSchemaMatchesPayload($contract, 'SearchProduct', $payload['data'][0]);
        }
    }

    public function test_product_search_validation_response_matches_gateway_and_search_contracts(): void
    {
        $payload = $this->getJson('/api/search/products')
            ->assertUnprocessable()
            ->assertHeader('content-type', 'application/json')
            ->json();

        foreach ($this->searchContracts() as $contract) {
            $this->assertContractDeclaresResponse($contract, '/api/search/products', '422', 'ErrorResponse');
            $this->assertSchemaMatchesPayload($contract, 'ErrorResponse', $payload);
        }
    }

    /**
     * @return array<string, string>
     */
    private function searchContracts(): array
    {
        return [
            'gateway' => base_path('services/gateway/contracts/openapi.yaml'),
            'search' => base_path('services/search/contracts/openapi.yaml'),
        ];
    }
}
