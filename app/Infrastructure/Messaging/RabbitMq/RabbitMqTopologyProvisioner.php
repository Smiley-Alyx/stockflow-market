<?php

namespace App\Infrastructure\Messaging\RabbitMq;

class RabbitMqTopologyProvisioner
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $connections,
        private readonly DomainEventTopology $domainEvents,
        private readonly ProviderOutcomeTopology $providerOutcomes,
        private readonly RabbitMqRuntimePermissions $runtimePermissions,
    ) {}

    public function provision(): void
    {
        $connection = $this->connections->create();
        $channel = $connection->channel();

        try {
            $this->domainEvents->declareConsumer($channel);
            $this->providerOutcomes->declareQueue($channel);
            $this->providerOutcomes->declare($channel);
            $this->runtimePermissions->apply();
        } finally {
            $channel->close();
            $connection->close();
        }
    }
}
