<?php

namespace App\Infrastructure\Messaging;

use Closure;

class DomainEventContext
{
    private static ?string $messageId = null;

    public static function messageId(): ?string
    {
        return self::$messageId;
    }

    public static function eventId(object $event): string
    {
        return self::$messageId ?? sha1($event::class.json_encode($event->payload(), JSON_THROW_ON_ERROR));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withMessageId(string $messageId, Closure $callback): mixed
    {
        $previous = self::$messageId;
        self::$messageId = $messageId;

        try {
            return $callback();
        } finally {
            self::$messageId = $previous;
        }
    }
}
