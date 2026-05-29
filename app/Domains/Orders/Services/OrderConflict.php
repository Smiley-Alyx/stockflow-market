<?php

namespace App\Domains\Orders\Services;

use RuntimeException;

class OrderConflict extends RuntimeException
{
    public static function emptyCart(): self
    {
        return new self('Cart is empty.');
    }

    public static function missingActivePrice(int $productId): self
    {
        return new self("Active price not found for product {$productId}.");
    }

    public static function mixedCurrencies(): self
    {
        return new self('Cart contains prices in multiple currencies.');
    }

    public static function orderIsNotDraft(int $orderId): self
    {
        return new self("Order {$orderId} is not draft.");
    }

    public static function invalidStatusTransition(int $orderId, string $status, string $targetStatus): self
    {
        return new self("Order {$orderId} cannot be moved from {$status} to {$targetStatus}.");
    }
}
