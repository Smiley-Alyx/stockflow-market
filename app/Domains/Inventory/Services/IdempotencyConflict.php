<?php

namespace App\Domains\Inventory\Services;

use RuntimeException;

class IdempotencyConflict extends RuntimeException
{
    public static function reservation(string $idempotencyKey): self
    {
        return new self("Reservation idempotency key {$idempotencyKey} was already used with a different request.");
    }
}
