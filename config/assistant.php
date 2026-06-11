<?php

use App\Domains\Assistant\Services\OpenAiShoppingAssistant;

return [
    'default' => env('AI_ASSISTANT_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'driver' => OpenAiShoppingAssistant::class,
            'name' => 'OpenAI',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 30),
        ],
        'gigachat' => [
            'driver' => env('GIGACHAT_ASSISTANT_DRIVER'),
            'name' => 'GigaChat',
        ],
        'yandex' => [
            'driver' => env('YANDEX_ASSISTANT_DRIVER'),
            'name' => 'Yandex AI',
        ],
    ],
];
