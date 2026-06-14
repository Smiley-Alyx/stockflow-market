<?php

namespace App\Infrastructure\Messaging;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_name',
    'schema_version',
    'aggregate_type',
    'aggregate_id',
    'payload',
    'status',
    'attempts',
    'available_at',
    'published_at',
    'last_error',
])]
class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $table = 'messaging_outbox';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
