<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\RabbitMq\DomainEventTopology;
use App\Infrastructure\Messaging\RabbitMq\ProviderOutcomeTopology;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqRuntimePermissions;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqTopologyProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

class RabbitMqTopologyProvisionerTest extends TestCase
{
    public function test_deployment_provisioner_declares_all_market_topology(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('close')->once();
        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connection->shouldReceive('close')->once();
        $connections = Mockery::mock(RabbitMqConnectionFactory::class);
        $connections->shouldReceive('create')->once()->andReturn($connection);
        $domainEvents = Mockery::mock(DomainEventTopology::class);
        $domainEvents->shouldReceive('declareConsumer')->once()->with($channel);
        $providerOutcomes = Mockery::mock(ProviderOutcomeTopology::class);
        $providerOutcomes->shouldReceive('declareQueue')->once()->with($channel);
        $providerOutcomes->shouldReceive('declare')->once()->with($channel);
        $runtimePermissions = Mockery::mock(RabbitMqRuntimePermissions::class);
        $runtimePermissions->shouldReceive('apply')->once();

        (new RabbitMqTopologyProvisioner(
            $connections,
            $domainEvents,
            $providerOutcomes,
            $runtimePermissions,
        ))->provision();
    }

    public function test_runtime_user_receives_no_configure_permission(): void
    {
        Http::fake();

        $this->app->make(RabbitMqRuntimePermissions::class)->apply();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://localhost:15672/api/users/stockflow-market-runtime'
            && $request['tags'] === []);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://localhost:15672/api/permissions/%2F/stockflow-market-runtime'
            && $request['configure'] === '^$'
            && str_contains($request['write'], 'domain')
            && str_contains($request['read'], 'provider'));
    }

    public function test_runtime_messaging_paths_do_not_declare_topology(): void
    {
        foreach ([
            'app/Infrastructure/Messaging/RabbitMq/DomainEventRabbitMqPublisher.php',
            'app/Infrastructure/Messaging/RabbitMq/DomainEventConsumer.php',
            'app/Infrastructure/Messaging/RabbitMq/ProviderOutcomeConsumer.php',
            'app/Infrastructure/Messaging/RabbitMq/ProviderOutcomeDeadLetterManager.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));

            $this->assertStringNotContainsString('->declare', $source, $path);
        }
    }
}
