<?php

namespace App\Domains\Inventory\Services;

use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public static function available(string $sku, int $requested, int $available): self
    {
        return new self("Insufficient available stock for {$sku}: requested {$requested}, available {$available}.");
    }

    public static function reserved(string $sku, int $requested, int $reserved): self
    {
        return new self("Insufficient reserved stock for {$sku}: requested {$requested}, reserved {$reserved}.");
    }
}
