<?php

namespace App\Domains\Assistant\Contracts;

interface ShoppingAssistantProvider
{
    public function code(): string;

    public function name(): string;

    /**
     * @return array{message: string, conversation_id: string|null, products: array<int, array<string, mixed>>}
     */
    public function respond(string $message, ?string $conversationId = null): array;
}
