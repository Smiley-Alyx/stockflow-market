<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\RabbitMqTopologyProvisioner;
use Illuminate\Console\Command;

class ProvisionRabbitMqTopologyCommand extends Command
{
    protected $signature = 'messaging:rabbitmq:provision';

    protected $description = 'Provision RabbitMQ topology and runtime permissions.';

    public function handle(RabbitMqTopologyProvisioner $provisioner): int
    {
        $provisioner->provision();
        $this->info('RabbitMQ topology and runtime permissions provisioned.');

        return self::SUCCESS;
    }
}
