<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeConsumer;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeProcessor;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeTopology;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Tests\TestCase;

class ProviderOutcomeConsumerTest extends TestCase
{
    public function test_failed_provider_outcome_is_sent_to_retry_queue(): void
    {
        $published = $this->consumeFailedMessage(0);

        $this->assertSame('stockflow.market.provider.outcomes.retry', $published['exchange']);
        $this->assertSame(1, $published['headers']['retry_count']);
    }

    public function test_exhausted_provider_outcome_is_sent_to_dead_letter_queue(): void
    {
        $published = $this->consumeFailedMessage(3);

        $this->assertSame('stockflow.market.provider.outcomes.dlx', $published['exchange']);
        $this->assertSame(4, $published['headers']['retry_count']);
    }

    public function test_provider_outcome_is_requeued_when_retry_publish_fails(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')->once()->andThrow(new RuntimeException('RabbitMQ is unavailable.'));

        $message = $this->failedMessage($channel, 0);
        $message->shouldReceive('nack')->once()->with(true);

        $this->consumer()->consumeMessage($message);
    }

    public function test_provider_outcome_topology_subscribes_projection_to_reservation_requests(): void
    {
        $bindings = [];
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('exchange_declare')->times(6);
        $channel->shouldReceive('queue_bind')
            ->times(20)
            ->withArgs(function (string $queue, string $exchange, string $routingKey) use (&$bindings): bool {
                $bindings[] = [$queue, $exchange, $routingKey];

                return true;
            });
        $topology = new ProviderOutcomeTopology;

        $topology->declare($channel);

        $this->assertContains([
            $topology->queue(),
            'stockflow.inventory',
            'inventory.reservation.requested.v1',
        ], $bindings);
        $this->assertContains([
            $topology->queue(),
            'stockflow.inventory',
            'inventory.reservation.release.requested.v1',
        ], $bindings);
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

                return $routingKey === 'inventory.reservation.confirmed.v1';
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
            'message_id' => 'msg-demo-001',
            'retry_count' => $retryCount,
        ]));
        $message->shouldReceive('getBody')->andReturn('{invalid json');
        $message->shouldReceive('getRoutingKey')->andReturn('inventory.reservation.confirmed.v1');
        $message->shouldReceive('getChannel')->andReturn($channel);

        return $message;
    }

    private function consumer(): ProviderOutcomeConsumer
    {
        return new ProviderOutcomeConsumer(
            Mockery::mock(RabbitMqConnectionFactory::class),
            new ProviderOutcomeTopology,
            Mockery::mock(ProviderOutcomeProcessor::class),
        );
    }
}
