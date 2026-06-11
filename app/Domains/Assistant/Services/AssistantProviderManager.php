<?php

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\ShoppingAssistantProvider;
use App\Domains\Assistant\Exceptions\AssistantConfigurationException;

class AssistantProviderManager
{
    /**
     * @return array{message: string, conversation_id: string|null, products: array<int, array<string, mixed>>, provider: array{code: string, name: string}}
     */
    public function respond(string $message, ?string $conversationId = null): array
    {
        $provider = $this->provider();

        return array_merge($provider->respond($message, $conversationId), [
            'provider' => [
                'code' => $provider->code(),
                'name' => $provider->name(),
            ],
        ]);
    }

    public function provider(): ShoppingAssistantProvider
    {
        $code = (string) config('assistant.default');
        $driver = config('assistant.providers.'.$code.'.driver');

        if (! is_string($driver) || $driver === '' || ! class_exists($driver)) {
            throw new AssistantConfigurationException(
                "Для AI-провайдера {$code} не настроен класс адаптера.",
            );
        }

        $provider = app($driver);

        if (! $provider instanceof ShoppingAssistantProvider) {
            throw new AssistantConfigurationException(
                "Адаптер AI-провайдера {$code} должен реализовывать ShoppingAssistantProvider.",
            );
        }

        return $provider;
    }
}
