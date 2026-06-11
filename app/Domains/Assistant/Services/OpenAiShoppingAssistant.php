<?php

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\ShoppingAssistantProvider;
use App\Domains\Assistant\Exceptions\AssistantConfigurationException;
use App\Domains\Assistant\Tools\CatalogSearchTool;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiShoppingAssistant implements ShoppingAssistantProvider
{
    public function __construct(
        private readonly CatalogSearchTool $catalog,
    ) {}

    public function code(): string
    {
        return 'openai';
    }

    public function name(): string
    {
        return (string) config('assistant.providers.openai.name');
    }

    /**
     * @return array{message: string, conversation_id: string|null, products: array<int, array<string, mixed>>}
     */
    public function respond(string $message, ?string $conversationId = null): array
    {
        if (! is_string(config('assistant.providers.openai.api_key')) || config('assistant.providers.openai.api_key') === '') {
            throw new AssistantConfigurationException('Для провайдера OpenAI не настроен OPENAI_API_KEY.');
        }

        $responseId = $conversationId;
        $products = [];
        $input = $message;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->request()->post('/v1/responses', array_filter([
                'model' => config('assistant.providers.openai.model'),
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
                    'conversation_id' => $responseId,
                    'products' => array_values($products),
                ];
            }

            $input = $calls->map(function (array $call) use (&$products): array {
                $arguments = json_decode((string) ($call['arguments'] ?? '{}'), true);
                $result = $this->catalog->execute(is_array($arguments) ? $arguments : []);

                foreach ($result as $product) {
                    $products[(int) ($product['id'] ?? 0)] = $product;
                }

                return [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($call['call_id'] ?? ''),
                    'output' => json_encode($this->catalog->context($result), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
            })->all();
        }

        throw new RuntimeException('OpenAI assistant exceeded the tool call limit.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('assistant.providers.openai.base_url'), '/'))
            ->withToken((string) config('assistant.providers.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('assistant.providers.openai.timeout_seconds'));
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
            'parameters' => $this->catalog->jsonSchema(),
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
}
