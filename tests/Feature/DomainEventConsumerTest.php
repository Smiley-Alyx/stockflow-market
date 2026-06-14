<?php

namespace Tests\Feature;

use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Jobs\IndexSearchDocument;
use App\Infrastructure\Messaging\InboxMessage;
use App\Infrastructure\Messaging\RabbitMq\DomainEventConsumer;
use App\Infrastructure\Messaging\RabbitMq\DomainEventProcessor;
use App\Infrastructure\Messaging\RabbitMq\DomainEventTopology;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Tests\TestCase;

class DomainEventConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_domain_event_delivery_is_processed_once(): void
    {
        Queue::fake();
        $event = new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']);
        $envelope = [
            'event_name' => SearchIndexRequested::NAME,
            'schema_version' => 1,
            'payload' => $event->payload(),
        ];
        $headers = ['message_id' => 'gateway:domain-outbox:15'];
        $processor = $this->app->make(DomainEventProcessor::class);

        $processor->process($headers, $envelope);
        $processor->process($headers, $envelope);

        Queue::assertPushed(IndexSearchDocument::class, 1);
        $this->assertDatabaseHas('messaging_inbox', [
            'message_id' => 'gateway:domain-outbox:15',
            'consumer' => DomainEventProcessor::class,
            'status' => InboxMessage::STATUS_PROCESSED,
        ]);
    }

    public function test_unsupported_domain_event_version_is_rejected_before_dispatch(): void
    {
        Event::fake();
        $event = new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']);

        $this->expectExceptionMessage('Unsupported search.index.requested schema version: 2');

        try {
            $this->app->make(DomainEventProcessor::class)->process(
                ['message_id' => 'gateway:domain-outbox:16'],
                [
                    'event_name' => SearchIndexRequested::NAME,
                    'schema_version' => 2,
                    'payload' => $event->payload(),
                ],
            );
        } finally {
            Event::assertNotDispatched(SearchIndexRequested::class);
        }
    }

    public function test_domain_event_payload_must_match_envelope_name(): void
    {
        Event::fake();
        $event = new SearchIndexRequested('catalog_products', '15', ['sku' => 'SCAN-001']);
        $payload = $event->payload();
        $payload['event'] = SearchIndexDeletionRequested::NAME;

        $this->expectExceptionMessage('Domain event payload does not match its envelope name.');

        try {
            $this->app->make(DomainEventProcessor::class)->process(
                ['message_id' => 'gateway:domain-outbox:17'],
                [
                    'event_name' => SearchIndexRequested::NAME,
                    'schema_version' => 1,
                    'payload' => $payload,
                ],
            );
        } finally {
            Event::assertNotDispatched(SearchIndexRequested::class);
        }
    }

    public function test_failed_domain_event_is_sent_to_retry_queue(): void
    {
        $published = $this->consumeFailedMessage(0);

        $this->assertSame('stockflow.domain.events.retry', $published['exchange']);
        $this->assertSame(1, $published['headers']['retry_count']);
    }

    public function test_exhausted_domain_event_is_sent_to_dead_letter_queue(): void
    {
        $published = $this->consumeFailedMessage(3);

        $this->assertSame('stockflow.domain.events.dlx', $published['exchange']);
        $this->assertSame(4, $published['headers']['retry_count']);
    }

    public function test_domain_event_is_requeued_when_retry_publish_fails(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')->once()->andThrow(new RuntimeException('RabbitMQ is unavailable.'));

        $message = $this->failedMessage($channel, 0);
        $message->shouldReceive('nack')->once()->with(true);

        $this->consumer()->consumeMessage($message);
    }

    /**
     * @return array{exchange: string, headers: array<string, mixed>}
     */
    private function consumeFailedMessage(int $retryCount): array
    {
        $published = [];
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $message, string $exchange, string $routingKey) use (&$published): bool {
                $published = [
                    'exchange' => $exchange,
                    'headers' => $message->get('application_headers')->getNativeData(),
                ];

                return $routingKey === SearchIndexRequested::NAME;
            });

        $message = $this->failedMessage($channel, $retryCount);
        $message->shouldReceive('ack')->once();

        $this->consumer()->consumeMessage($message);

        return $published;
    }

    private function failedMessage(AMQPChannel $channel, int $retryCount): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('has')->with('application_headers')->andReturn(true);
        $message->shouldReceive('get')->with('application_headers')->andReturn(new AMQPTable([
            'message_id' => 'gateway:domain-outbox:15',
            'retry_count' => $retryCount,
        ]));
        $message->shouldReceive('getBody')->andReturn('{invalid json');
        $message->shouldReceive('getRoutingKey')->andReturn(SearchIndexRequested::NAME);
        $message->shouldReceive('getChannel')->andReturn($channel);

        return $message;
    }

    private function consumer(): DomainEventConsumer
    {
        return new DomainEventConsumer(
            Mockery::mock(RabbitMqConnectionFactory::class),
            new DomainEventTopology,
            Mockery::mock(DomainEventProcessor::class),
        );
    }
}
