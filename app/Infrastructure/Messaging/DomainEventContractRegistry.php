<?php

namespace App\Infrastructure\Messaging;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Inventory\Models\StockItem;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Events\OrderCreated;
use App\Domains\Orders\Events\OrderExpired;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Events\OrderReservationFailed;
use App\Domains\Orders\Events\OrderReservationSucceeded;
use App\Domains\Orders\Models\Order;
use App\Domains\Search\Events\SearchIndexCompleted;
use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Events\SearchIndexFailed;
use App\Domains\Search\Events\SearchIndexRequested;
use InvalidArgumentException;

class DomainEventContractRegistry
{
    public const VERSION = 1;

    /**
     * @return array{event_name: string, schema_version: int, payload: array<string, mixed>}
     */
    public function contract(object $event): array
    {
        if (! method_exists($event, 'payload')) {
            throw new InvalidArgumentException('Domain event must expose a payload method.');
        }

        $eventName = defined($event::class.'::NAME') ? constant($event::class.'::NAME') : null;

        if (! is_string($eventName) || ! array_key_exists($eventName, $this->eventClasses())) {
            throw new InvalidArgumentException('Unsupported domain event: '.$event::class);
        }

        if ($this->eventClasses()[$eventName] !== $event::class) {
            throw new InvalidArgumentException('Domain event name does not match its registered class.');
        }

        $payload = $event->payload();

        if (($payload['event'] ?? null) !== $eventName) {
            throw new InvalidArgumentException('Domain event payload does not match its registered name.');
        }

        return [
            'event_name' => $eventName,
            'schema_version' => self::VERSION,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function restore(string $eventName, int $schemaVersion, array $payload): object
    {
        if ($schemaVersion !== self::VERSION) {
            throw new InvalidArgumentException("Unsupported {$eventName} schema version: {$schemaVersion}");
        }

        if (($payload['event'] ?? null) !== $eventName) {
            throw new InvalidArgumentException('Domain event payload does not match its envelope name.');
        }

        return match ($eventName) {
            ProductCreated::NAME => new ProductCreated($this->product($payload)),
            ProductUpdated::NAME => new ProductUpdated($this->product($payload)),
            ProductArchived::NAME => new ProductArchived($this->product($payload)),
            StockChanged::NAME => new StockChanged(
                StockItem::query()->where([
                    'warehouse_id' => $this->requiredInt($payload, 'stock.warehouse_id'),
                    'product_id' => $this->requiredInt($payload, 'stock.product_id'),
                ])->firstOrFail(),
                StockMovement::query()->findOrFail($this->requiredInt($payload, 'movement.id')),
            ),
            OrderConfirmationRequested::NAME => new OrderConfirmationRequested($this->order($payload)),
            OrderCreated::NAME => new OrderCreated($this->order($payload)),
            OrderReservationSucceeded::NAME => new OrderReservationSucceeded($this->order($payload)),
            OrderReservationFailed::NAME => new OrderReservationFailed(
                $this->order($payload),
                $this->requiredString($payload, 'reason'),
            ),
            OrderPaid::NAME => new OrderPaid($this->order($payload)),
            OrderCancelled::NAME => new OrderCancelled($this->order($payload)),
            OrderExpired::NAME => new OrderExpired($this->order($payload)),
            SearchIndexRequested::NAME => new SearchIndexRequested(
                $this->requiredString($payload, 'index'),
                $this->requiredString($payload, 'document_id'),
                $this->requiredArray($payload, 'document'),
            ),
            SearchIndexDeletionRequested::NAME => new SearchIndexDeletionRequested(
                $this->requiredString($payload, 'index'),
                $this->requiredString($payload, 'document_id'),
            ),
            SearchIndexCompleted::NAME => new SearchIndexCompleted(
                $this->requiredString($payload, 'index'),
                $this->requiredString($payload, 'document_id'),
            ),
            SearchIndexFailed::NAME => new SearchIndexFailed(
                $this->requiredString($payload, 'index'),
                $this->requiredString($payload, 'document_id'),
                $this->requiredInt($payload, 'attempts'),
                $this->requiredString($payload, 'failure'),
            ),
            default => throw new InvalidArgumentException("Unsupported domain event: {$eventName}"),
        };
    }

    /**
     * @return array<string, class-string>
     */
    private function eventClasses(): array
    {
        return [
            ProductCreated::NAME => ProductCreated::class,
            ProductUpdated::NAME => ProductUpdated::class,
            ProductArchived::NAME => ProductArchived::class,
            StockChanged::NAME => StockChanged::class,
            OrderConfirmationRequested::NAME => OrderConfirmationRequested::class,
            OrderCreated::NAME => OrderCreated::class,
            OrderReservationSucceeded::NAME => OrderReservationSucceeded::class,
            OrderReservationFailed::NAME => OrderReservationFailed::class,
            OrderPaid::NAME => OrderPaid::class,
            OrderCancelled::NAME => OrderCancelled::class,
            OrderExpired::NAME => OrderExpired::class,
            SearchIndexRequested::NAME => SearchIndexRequested::class,
            SearchIndexDeletionRequested::NAME => SearchIndexDeletionRequested::class,
            SearchIndexCompleted::NAME => SearchIndexCompleted::class,
            SearchIndexFailed::NAME => SearchIndexFailed::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function product(array $payload): Product
    {
        return Product::query()->findOrFail($this->requiredInt($payload, 'product.id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function order(array $payload): Order
    {
        return Order::query()->findOrFail($this->requiredInt($payload, 'order_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requiredArray(array $payload, string $key): array
    {
        $value = data_get($payload, $key);

        if (! is_array($value)) {
            throw new InvalidArgumentException("Missing domain event field: {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredInt(array $payload, string $key): int
    {
        $value = data_get($payload, $key);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Missing domain event field: {$key}");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = data_get($payload, $key);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing domain event field: {$key}");
        }

        return $value;
    }
}
