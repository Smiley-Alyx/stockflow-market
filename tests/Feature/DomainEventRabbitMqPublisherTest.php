<?php

namespace Tests\Feature;

use App\Domains\Search\Events\SearchIndexRequested;
use App\Infrastructure\Messaging\DomainEventPublisher;
use App\Infrastructure\Messaging\DomainEventRecorder;
use App\Infrastructure\Messaging\OutboxMessage;
use App\Infrastructure\Messaging\RabbitMq\DomainEventRabbitMqPublisher;
use App\Infrastructure\Messaging\RabbitMq\DomainEventTopology;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class DomainEventRabbitMqPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_event_is_published_with_stable_envelope_and_confirmation(): void
    {
        $event = new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']);
        $message = $this->app->make(DomainEventRecorder::class)->record($event, 'product', '15');
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $published, string $exchange, string $routingKey) use ($event, $message): bool {
                $headers = $published->get('application_headers')->getNativeData();
                $envelope = json_decode($published->getBody(), true, flags: JSON_THROW_ON_ERROR);

                return $exchange === 'stockflow.domain.events'
                    && $routingKey === SearchIndexRequested::NAME
                    && $headers['message_id'] === 'gateway:domain-outbox:'.$message->id
                    && $headers['retry_count'] === 0
                    && $headers['schema_version'] === 1
                    && $envelope['event_name'] === SearchIndexRequested::NAME
                    && $envelope['schema_version'] === 1
                    && $envelope['aggregate_type'] === 'product'
                    && $envelope['aggregate_id'] === '15'
                    && $envelope['payload'] === $event->payload()
                    && ! array_key_exists('serialized_event', $envelope);
            });
        $channel->shouldReceive('wait_for_pending_acks')->once()->with(5);
        $channel->shouldReceive('close')->once();

        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connection->shouldReceive('close')->once();

        $connections = Mockery::mock(RabbitMqConnectionFactory::class);
        $connections->shouldReceive('create')->once()->andReturn($connection);
        $topology = Mockery::mock(DomainEventTopology::class);
        $topology->shouldNotReceive('declarePublisher');
        $topology->shouldNotReceive('declareConsumer');
        $topology->shouldReceive('exchange')->once()->andReturn('stockflow.domain.events');

        $publisher = new DomainEventRabbitMqPublisher($connections, $topology);
        $publisher->publish($message);

        $this->assertSame(1, $message->schema_version);
        $this->assertFalse(Schema::hasColumn('messaging_outbox', 'serialized_event'));
        $this->assertFalse(Schema::hasColumn('messaging_outbox', 'event_class'));
    }

    public function test_domain_outbox_uses_rabbitmq_transport_without_in_process_dispatch(): void
    {
        config(['stockflow.messaging.event_bus' => 'rabbitmq']);

        $event = new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']);
        $message = $this->app->make(DomainEventRecorder::class)->record($event, 'product', '15');
        $rabbitMq = Mockery::mock(DomainEventRabbitMqPublisher::class);
        $rabbitMq->shouldReceive('publish')->once()->withArgs(fn (OutboxMessage $published): bool => $published->is($message));
        $this->app->instance(DomainEventRabbitMqPublisher::class, $rabbitMq);

        $published = $this->app->make(DomainEventPublisher::class)->publishPending();

        $this->assertSame(1, $published);
        $this->assertDatabaseHas('messaging_outbox', [
            'id' => $message->id,
            'status' => OutboxMessage::STATUS_PUBLISHED,
        ]);
        $this->assertDatabaseMissing('messaging_inbox', [
            'message_id' => (string) $message->id,
        ]);
    }
}
