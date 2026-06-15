<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use Illuminate\Support\Facades\Http;

class RabbitMqRuntimePermissions
{
    public function apply(): void
    {
        $user = (string) config('stockflow.messaging.rabbitmq.provisioning.runtime_user');
        $client = Http::timeout((float) config('stockflow.runtime.dependency_timeout_seconds'))
            ->withBasicAuth(
                (string) config('stockflow.dependencies.rabbitmq.user'),
                (string) config('stockflow.dependencies.rabbitmq.password'),
            );

        $client->put($this->managementUrl().'/api/users/'.rawurlencode($user), [
            'password' => (string) config('stockflow.messaging.rabbitmq.provisioning.runtime_password'),
            'tags' => [],
        ])->throw();

        $client->put(
            $this->managementUrl().'/api/permissions/'
                .rawurlencode((string) config('stockflow.dependencies.rabbitmq.vhost'))
                .'/'.rawurlencode($user),
            [
                'configure' => '^$',
                'write' => (string) config('stockflow.messaging.rabbitmq.provisioning.runtime_write_permission'),
                'read' => (string) config('stockflow.messaging.rabbitmq.provisioning.runtime_read_permission'),
            ],
        )->throw();
    }

    private function managementUrl(): string
    {
        return rtrim((string) config('stockflow.observability.rabbitmq_management_url'), '/');
    }
}
