<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\ProviderMessageRecorder;
use App\Infrastructure\Messaging\ProviderOutboxMessage;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutboxPublisher;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use App\Infrastructure\Resilience\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class ProviderOutboxPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(CircuitBreaker::class)->reset('rabbitmq');
    }

    public function test_provider_outbox_recovers_stale_processing_claim_before_circuit_breaker_check(): void
    {
        $message = $this->message();
        DB::table('messaging_provider_outbox')->where('id', $message->id)->update([
            'status' => ProviderOutboxMessage::STATUS_PROCESSING,
            'updated_at' => now()->subMinutes(2),
        ]);
        $this->app->make(CircuitBreaker::class)->open('rabbitmq');

        $published = $this->publisher()->publishPending();

        $this->assertSame(0, $published);
        $this->assertDatabaseHas('messaging_provider_outbox', [
            'id' => $message->id,
            'status' => ProviderOutboxMessage::STATUS_FAILED,
            'last_error' => 'Recovered stale processing claim.',
        ]);
    }

    public function test_provider_outbox_waits_for_confirmation_and_increments_retry_header(): void
    {
        $message = $this->message();
        $message->update([
            'status' => ProviderOutboxMessage::STATUS_FAILED,
            'attempts' => 1,
        ]);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $published, string $exchange, string $routingKey): bool {
                $headers = $published->get('application_headers')->getNativeData();

                return $exchange === 'stockflow.inventory'
                    && $routingKey === 'inventory.reservation.requested.v1'
                    && $headers['retry_count'] === 1;
            });
        $channel->shouldReceive('wait_for_pending_acks')->once()->with(5);
        $channel->shouldReceive('close')->once();

        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connection->shouldReceive('close')->once();

        $connections = Mockery::mock(RabbitMqConnectionFactory::class);
        $connections->shouldReceive('create')->once()->andReturn($connection);

        $published = $this->publisher($connections)->publishPending();

        $this->assertSame(1, $published);
        $this->assertDatabaseHas('messaging_provider_outbox', [
            'id' => $message->id,
            'status' => ProviderOutboxMessage::STATUS_PUBLISHED,
            'attempts' => 2,
        ]);
        $this->assertSame(1, $message->fresh()->headers['retry_count']);
    }

    private function message(): ProviderOutboxMessage
    {
        return $this->app->make(ProviderMessageRecorder::class)->record(
            exchange: 'stockflow.inventory',
            routingKey: 'inventory.reservation.requested.v1',
            correlationId: (string) Str::uuid(),
            idempotencyKey: 'reserve:res-demo-001',
            payload: ['reservation_id' => 'res-demo-001'],
        );
    }

    private function publisher(?RabbitMqConnectionFactory $connections = null): ProviderOutboxPublisher
    {
        return new ProviderOutboxPublisher(
            $connections ?? Mockery::mock(RabbitMqConnectionFactory::class),
            $this->app->make(CircuitBreaker::class),
        );
    }
}
