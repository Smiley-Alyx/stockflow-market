<?php

namespace Tests\Feature;

use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiAssistantApiTest extends TestCase
{
    public function test_openai_assistant_searches_catalog_and_returns_grounded_response(): void
    {
        config([
            'assistant.default' => 'openai',
            'assistant.providers.openai.api_key' => 'test-key',
            'assistant.providers.openai.model' => 'gpt-5.4-mini',
            'assistant.providers.openai.base_url' => 'https://api.openai.com',
        ]);

        $this->app->instance(CatalogProductSearch::class, new class implements CatalogProductSearch
        {
            public function products(CatalogProductQuery $query): array
            {
                return [
                    'data' => [[
                        'id' => 15,
                        'name' => 'Aurora Room Speaker',
                        'slug' => 'speaker-room',
                        'url' => '/catalog/electronics/audio/speaker-room/',
                        'sku' => 'AUR-ROOM-SPK',
                        'short_description' => 'Домашняя Bluetooth-колонка.',
                        'attributes' => [['name' => 'Цвет', 'value' => 'Темно-синий']],
                        'price' => ['amount_minor' => 23800, 'currency' => 'PLN'],
                        'availability' => ['in_stock' => true, 'available_quantity' => 5],
                        'rating' => 4.8,
                    ]],
                    'meta' => ['total' => 1],
                ];
            }
        });

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'id' => 'resp_search',
                    'output' => [[
                        'type' => 'function_call',
                        'name' => 'search_catalog',
                        'call_id' => 'call_search',
                        'arguments' => json_encode([
                            'q' => 'колонка',
                            'color' => 'синий',
                            'max_price' => 250,
                            'in_stock' => true,
                            'sort' => 'rating_desc',
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ])
                ->push([
                    'id' => 'resp_answer',
                    'output' => [[
                        'type' => 'message',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'Подойдёт Aurora Room Speaker: она синяя, есть в наличии и укладывается в бюджет.',
                        ]],
                    ]],
                ]),
        ]);

        $this->postJson('/api/assistant/respond', [
            'message' => 'Нужна синяя колонка для кухни до 250',
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 'resp_answer')
            ->assertJsonPath('data.products.0.sku', 'AUR-ROOM-SPK')
            ->assertJsonPath('data.provider.code', 'openai')
            ->assertJsonPath('data.provider.name', 'OpenAI')
            ->assertJsonPath('data.message', 'Подойдёт Aurora Room Speaker: она синяя, есть в наличии и укладывается в бюджет.');

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'gpt-5.4-mini'
                && $request['tools'][0]['name'] === 'search_catalog';
        });
        Http::assertSent(function ($request): bool {
            return ($request['previous_response_id'] ?? null) === 'resp_search'
                && data_get($request->data(), 'input.0.type') === 'function_call_output'
                && str_contains((string) data_get($request->data(), 'input.0.output'), 'Aurora Room Speaker');
        });
    }

    public function test_openai_assistant_reports_missing_api_key(): void
    {
        config([
            'assistant.default' => 'openai',
            'assistant.providers.openai.api_key' => null,
        ]);
        Http::fake();

        $this->postJson('/api/assistant/respond', ['message' => 'Помоги выбрать подарок'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Для провайдера OpenAI не настроен OPENAI_API_KEY.');

        Http::assertNothingSent();
    }

    public function test_assistant_reports_provider_without_adapter(): void
    {
        config([
            'assistant.default' => 'gigachat',
            'assistant.providers.gigachat.driver' => null,
        ]);

        $this->postJson('/api/assistant/respond', ['message' => 'Помоги выбрать подарок'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Для AI-провайдера gigachat не настроен класс адаптера.');
    }
}
