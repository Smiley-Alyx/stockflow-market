<?php

namespace App\Infrastructure\Messaging;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['exchange', 'routing_key', 'message_id', 'correlation_id', 'causation_id', 'idempotency_key', 'headers', 'payload', 'status', 'attempts', 'available_at', 'published_at', 'last_error'])]
class ProviderOutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $table = 'messaging_provider_outbox';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
