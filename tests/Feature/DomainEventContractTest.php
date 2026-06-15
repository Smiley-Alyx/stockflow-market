<?php

namespace Tests\Feature;

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
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Search\Events\SearchIndexCompleted;
use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Events\SearchIndexFailed;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Infrastructure\Messaging\DomainEventContractRegistry;
use App\Infrastructure\Messaging\DomainEventSchemaRegistry;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

class DomainEventContractTest extends TestCase
{
    public function test_every_registered_event_has_a_versioned_payload_schema(): void
    {
        $contracts = $this->app->make(DomainEventContractRegistry::class);
        $schemas = $this->app->make(DomainEventSchemaRegistry::class);

        $registeredEvents = array_keys($contracts->eventClasses());
        $schemaEvents = array_keys($schemas->events());
        sort($registeredEvents);
        sort($schemaEvents);

        $this->assertSame($registeredEvents, $schemaEvents);

        foreach ($schemas->events() as $eventName => $path) {
            $schema = $schemas->schema($eventName);

            $this->assertStringStartsWith('v1/', $path);
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
            $this->assertSame($eventName, $schema['properties']['event']['const']);
            $this->assertContains('event', $schema['required']);
        }
    }

    public function test_producers_emit_payloads_that_match_their_json_schemas(): void
    {
        $contracts = $this->app->make(DomainEventContractRegistry::class);

        foreach ($this->events() as $event) {
            $contract = $contracts->contract($event);

            $this->assertSame($event::NAME, $contract['event_name']);
            $this->assertSame(DomainEventContractRegistry::VERSION, $contract['schema_version']);
            $this->assertSame($event->payload(), $contract['payload']);
        }
    }

    public function test_consumer_rejects_a_payload_when_a_required_field_is_missing(): void
    {
        $contracts = $this->app->make(DomainEventContractRegistry::class);
        $schemas = $this->app->make(DomainEventSchemaRegistry::class);

        foreach ($this->events() as $event) {
            $payload = $event->payload();
            $requiredField = collect($schemas->schema($event::NAME)['required'])
                ->first(fn (string $field): bool => $field !== 'event');
            unset($payload[$requiredField]);

            try {
                $contracts->restore($event::NAME, DomainEventContractRegistry::VERSION, $payload);
                $this->fail('Consumer accepted incompatible '.$event::NAME.' payload.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) $requiredField, $exception->getMessage());
            }
        }
    }

    public function test_consumer_restores_valid_stateless_event_payloads(): void
    {
        $contracts = $this->app->make(DomainEventContractRegistry::class);
        $events = [
            new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']),
            new SearchIndexDeletionRequested('catalog_products', '15'),
            new SearchIndexCompleted('catalog_products', '15'),
            new SearchIndexFailed('catalog_products', '15', 3, 'Elasticsearch unavailable.'),
        ];

        foreach ($events as $event) {
            $restored = $contracts->restore($event::NAME, DomainEventContractRegistry::VERSION, $event->payload());

            $this->assertInstanceOf($event::class, $restored);
            $this->assertSame($event->payload(), $restored->payload());
        }
    }

    /**
     * @return list<object>
     */
    private function events(): array
    {
        $product = new Product;
        $product->forceFill([
            'id' => 15,
            'category_id' => 7,
            'name' => 'Scanner',
            'slug' => 'scanner',
            'sku' => 'SCAN-001',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $stockItem = new StockItem;
        $stockItem->forceFill([
            'id' => 23,
            'warehouse_id' => 3,
            'product_id' => 15,
            'sku' => 'SCAN-001',
            'on_hand_quantity' => 10,
            'reserved_quantity' => 2,
        ]);

        $movement = new StockMovement;
        $movement->forceFill([
            'id' => 31,
            'type' => StockMovement::TYPE_RESERVED,
            'quantity' => 2,
            'reference_type' => 'order',
            'reference_id' => '42',
            'occurred_at' => now(),
        ]);

        $orderItem = new OrderItem;
        $orderItem->forceFill([
            'product_id' => 15,
            'sku' => 'SCAN-001',
            'quantity' => 2,
        ]);

        $order = new Order;
        $order->forceFill([
            'id' => 42,
            'status' => Order::STATUS_CONFIRMED,
            'total_amount_minor' => 19900,
            'currency' => 'RUB',
            'confirmed_at' => now(),
            'paid_at' => now(),
            'cancelled_at' => now(),
            'expired_at' => now(),
        ]);
        $order->setRelation('items', new Collection([$orderItem]));

        return [
            new ProductCreated($product),
            new ProductUpdated($product),
            new ProductArchived($product),
            new StockChanged($stockItem, $movement),
            new OrderConfirmationRequested($order),
            new OrderCreated($order),
            new OrderReservationSucceeded($order),
            new OrderReservationFailed($order, 'INSUFFICIENT_STOCK'),
            new OrderPaid($order),
            new OrderCancelled($order),
            new OrderExpired($order),
            new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']),
            new SearchIndexDeletionRequested('catalog_products', '15'),
            new SearchIndexCompleted('catalog_products', '15'),
            new SearchIndexFailed('catalog_products', '15', 3, 'Elasticsearch unavailable.'),
        ];
    }
}
