<?php

namespace App\Domains\Assistant\Services;

use App\Domains\Catalog\Search\CatalogProductQuery;
use App\Domains\Catalog\Search\CatalogProductSearch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiShoppingAssistant
{
    public function __construct(
        private readonly CatalogProductSearch $catalog,
    ) {}

    /**
     * @return array{message: string, response_id: string|null, products: array<int, array<string, mixed>>}
     */
    public function respond(string $message, ?string $previousResponseId = null): array
    {
        if (! is_string(config('services.openai.api_key')) || config('services.openai.api_key') === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $responseId = $previousResponseId;
        $products = [];
        $input = $message;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->request()->post('/v1/responses', array_filter([
                'model' => config('services.openai.model'),
                'instructions' => $this->instructions(),
                'input' => $input,
                'previous_response_id' => $responseId,
                'tools' => [$this->catalogTool()],
                'reasoning' => ['effort' => 'low'],
                'text' => ['verbosity' => 'low'],
            ], fn (mixed $value): bool => $value !== null))->throw()->json();

            $responseId = isset($response['id']) ? (string) $response['id'] : $responseId;
            $calls = collect($response['output'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item)
                    && ($item['type'] ?? null) === 'function_call'
                    && ($item['name'] ?? null) === 'search_catalog')
                ->values();

            if ($calls->isEmpty()) {
                return [
                    'message' => $this->responseText($response),
                    'response_id' => $responseId,
                    'products' => array_values($products),
                ];
            }

            $input = $calls->map(function (array $call) use (&$products): array {
                $arguments = json_decode((string) ($call['arguments'] ?? '{}'), true);
                $result = $this->searchCatalog(is_array($arguments) ? $arguments : []);

                foreach ($result as $product) {
                    $products[(int) ($product['id'] ?? 0)] = $product;
                }

                return [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($call['call_id'] ?? ''),
                    'output' => json_encode($this->catalogContext($result), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
            })->all();
        }

        throw new RuntimeException('OpenAI assistant exceeded the tool call limit.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
            ->withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.openai.timeout_seconds'));
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'search_catalog',
            'description' => 'Search the full current store catalog. Use it for product recommendations, comparisons, prices, availability, brands, and characteristics.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'q' => [
                        'type' => ['string', 'null'],
                        'description' => 'Short product search phrase in Russian without budget or filler words.',
                    ],
                    'color' => [
                        'type' => ['string', 'null'],
                        'description' => 'A short color fragment, for example синий or графит.',
                    ],
                    'max_price' => [
                        'type' => ['number', 'null'],
                        'description' => 'Maximum price in the catalog currency units.',
                    ],
                    'in_stock' => [
                        'type' => ['boolean', 'null'],
                        'description' => 'Whether to require current stock.',
                    ],
                    'sort' => [
                        'type' => ['string', 'null'],
                        'enum' => ['newest', 'price_asc', 'price_desc', 'rating_desc', null],
                    ],
                ],
                'required' => ['q', 'color', 'max_price', 'in_stock', 'sort'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
Ты — AI-консультант интернет-магазина StockFlow Market. Отвечай по-русски, кратко и полезно.
Ты умеешь понимать сложные бытовые сценарии, задавать уточняющие вопросы, сравнивать товары и объяснять выбор.
Для любых рекомендаций, сравнений, вопросов о цене, наличии или характеристиках обязательно используй search_catalog.
Основывай утверждения о товарах только на результате search_catalog. Не выдумывай товары, цены, наличие и характеристики.
Если подходящих товаров нет, сообщи об этом и предложи ослабить условия. Не оформляй заказ самостоятельно.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function searchCatalog(array $arguments): array
    {
        $result = $this->catalog->products(new CatalogProductQuery(
            query: $this->nullableString($arguments['q'] ?? null),
            category: null,
            categoryPath: null,
            filters: [],
            brands: [],
            inStock: is_bool($arguments['in_stock'] ?? null) ? $arguments['in_stock'] : null,
            cityCode: null,
            priceFrom: null,
            priceTo: is_numeric($arguments['max_price'] ?? null)
                ? max(0, (int) round((float) $arguments['max_price'] * 100))
                : null,
            sort: in_array($arguments['sort'] ?? null, ['newest', 'price_asc', 'price_desc', 'rating_desc'], true)
                ? $arguments['sort']
                : 'rating_desc',
            page: 1,
            perPage: 10,
            color: $this->nullableString($arguments['color'] ?? null),
        ));

        return array_slice($result['data'], 0, 10);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function catalogContext(array $products): array
    {
        return collect($products)->map(fn (array $product): array => [
            'id' => $product['id'] ?? null,
            'name' => $product['name'] ?? null,
            'category' => data_get($product, 'category.name'),
            'brand' => data_get($product, 'brand.name'),
            'description' => $product['short_description'] ?? $product['description'] ?? null,
            'price' => $product['price'] ?? null,
            'availability' => $product['availability'] ?? null,
            'rating' => $product['rating'] ?? null,
            'attributes' => $product['attributes'] ?? [],
            'url' => $product['url'] ?? null,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function responseText(array $response): string
    {
        $text = collect($response['output'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'message')
            ->flatMap(fn (array $item): array => is_array($item['content'] ?? null) ? $item['content'] : [])
            ->first(fn (mixed $content): bool => is_array($content) && ($content['type'] ?? null) === 'output_text');

        if (is_array($text) && is_string($text['text'] ?? null) && $text['text'] !== '') {
            return $text['text'];
        }

        throw new RuntimeException('OpenAI assistant returned no text.');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
