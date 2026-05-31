<?php

namespace App\Domains\Orders\Listeners;

use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Services\CheckoutSagaService;
use App\Infrastructure\Messaging\DomainEventContext;
use App\Infrastructure\Messaging\InboxConsumer;

class StartCheckoutSaga
{
    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly CheckoutSagaService $sagas,
    ) {}

    public function handle(OrderConfirmationRequested $event): void
    {
        if (! config('stockflow.provider_saga.enabled')) {
            return;
        }

        $this->inbox->consume($this->messageId($event), self::class, function () use ($event): void {
            $this->sagas->start($event->order->id);
        });
    }

    private function messageId(OrderConfirmationRequested $event): string
    {
        return DomainEventContext::messageId() ?? sha1($event::class.serialize($event->payload()));
    }
}
