<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Domains\Orders\Services\CheckoutSagaService;
use App\Infrastructure\Messaging\InboxConsumer;
use InvalidArgumentException;

class ProviderOutcomeProcessor
{
    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly CheckoutSagaService $sagas,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function process(string $routingKey, array $headers, array $payload): void
    {
        $messageId = $this->requiredHeader($headers, 'message_id');
        $correlationId = $this->requiredHeader($headers, 'correlation_id');

        $this->inbox->consume($messageId, self::class, function () use ($routingKey, $correlationId, $messageId, $payload): void {
            $this->sagas->handle($routingKey, $correlationId, $messageId, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function requiredHeader(array $headers, string $name): string
    {
        $value = $headers[$name] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing provider message header: {$name}");
        }

        return $value;
    }
}
