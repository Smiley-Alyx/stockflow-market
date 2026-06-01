<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeDeadLetterManager;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeTopology;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class ProviderOutcomeDeadLetterManagerTest extends TestCase
{
    public function test_provider_outcome_dead_letter_messages_can_be_listed_without_removal(): void
    {
        $message = $this->message();
        $message->shouldReceive('reject')->once()->with(true);
        [$manager, $channel, $connection, $topology] = $this->manager();
        $this->expectTopology($topology, $channel);
        $topology->shouldReceive('deadLetterQueue')->twice()->andReturn('stockflow.market.provider.outcomes.dlq');
        $channel->shouldReceive('basic_get')->once()->with('stockflow.market.provider.outcomes.dlq')->andReturn($message);
        $channel->shouldReceive('basic_get')->once()->with('stockflow.market.provider.outcomes.dlq')->andReturn(null);
        $channel->shouldReceive('close')->once();
        $connection->shouldReceive('close')->once();

        $messages = $manager->list();

        $this->assertSame('inventory.reservation.confirmed.v1', $messages[0]['routing_key']);
        $this->assertSame('msg-demo-001', $messages[0]['headers']['message_id']);
        $this->assertSame(['reservation_id' => 'res-demo-001'], $messages[0]['payload']);
    }

    public function test_provider_outcome_dead_letter_messages_can_be_requeued_after_confirmation(): void
    {
        $message = $this->message();
        $message->shouldReceive('ack')->once();
        [$manager, $channel, $connection, $topology] = $this->manager();
        $this->expectTopology($topology, $channel);
        $topology->shouldReceive('deadLetterQueue')->twice()->andReturn('stockflow.market.provider.outcomes.dlq');
        $topology->shouldReceive('retryReturnExchange')->once()->andReturn('stockflow.market.provider.outcomes.retry-return');
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_get')->once()->with('stockflow.market.provider.outcomes.dlq')->andReturn($message);
        $channel->shouldReceive('basic_get')->once()->with('stockflow.market.provider.outcomes.dlq')->andReturn(null);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $published, string $exchange, string $routingKey): bool {
                $headers = $published->get('application_headers')->getNativeData();

                return $exchange === 'stockflow.market.provider.outcomes.retry-return'
                    && $routingKey === 'inventory.reservation.confirmed.v1'
                    && $headers['retry_count'] === 0;
            });
        $channel->shouldReceive('wait_for_pending_acks')->once()->with(5);
        $channel->shouldReceive('close')->once();
        $connection->shouldReceive('close')->once();

        $this->assertSame(1, $manager->requeue());
    }

    /**
     * @return array{ProviderOutcomeDeadLetterManager, AMQPChannel, AMQPStreamConnection, ProviderOutcomeTopology}
     */
    private function manager(): array
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connections = Mockery::mock(RabbitMqConnectionFactory::class);
        $connections->shouldReceive('create')->once()->andReturn($connection);
        $topology = Mockery::mock(ProviderOutcomeTopology::class);

        return [new ProviderOutcomeDeadLetterManager($connections, $topology), $channel, $connection, $topology];
    }

    private function expectTopology(ProviderOutcomeTopology $topology, AMQPChannel $channel): void
    {
        $topology->shouldReceive('declareQueue')->once()->with($channel);
        $topology->shouldReceive('declare')->once()->with($channel);
    }

    private function message(): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getRoutingKey')->andReturn('inventory.reservation.confirmed.v1');
        $message->shouldReceive('getBody')->andReturn('{"reservation_id":"res-demo-001"}');
        $message->shouldReceive('has')->with('application_headers')->andReturn(true);
        $message->shouldReceive('get')->with('application_headers')->andReturn(new AMQPTable([
            'message_id' => 'msg-demo-001',
            'retry_count' => 3,
        ]));

        return $message;
    }
}
