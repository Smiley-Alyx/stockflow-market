<?php

namespace App\Domains\Orders\Read;

use App\Domains\Orders\Models\CheckoutSagaReservation;
use App\Domains\Orders\Models\ReservationStatusProjection;

class ReservationStatusProjector
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function project(string $routingKey, string $messageId, string $correlationId, array $payload): void
    {
        $status = $this->status($routingKey);

        if ($status === null) {
            return;
        }

        /** @var CheckoutSagaReservation $reservation */
        $reservation = CheckoutSagaReservation::query()
            ->with('saga')
            ->where('reservation_id', (string) $payload['reservation_id'])
            ->firstOrFail();

        /** @var ReservationStatusProjection|null $projection */
        $projection = ReservationStatusProjection::query()
            ->where('reservation_id', $reservation->reservation_id)
            ->lockForUpdate()
            ->first();

        if ($projection !== null && ! $this->allows($projection->status, $status)) {
            return;
        }

        $values = [
            'order_id' => $reservation->saga->order_id,
            'order_item_id' => $reservation->order_item_id,
            'correlation_id' => $correlationId,
            'status' => $status,
            'reason' => $this->reason($routingKey, $payload),
            'last_routing_key' => $routingKey,
            'last_message_id' => $messageId,
            'projected_at' => now(),
        ];

        if ($projection === null) {
            ReservationStatusProjection::query()->create([
                'reservation_id' => $reservation->reservation_id,
                ...$values,
            ]);

            return;
        }

        $projection->update($values);
    }

    private function status(string $routingKey): ?string
    {
        return match ($routingKey) {
            'inventory.reservation.requested.v1' => CheckoutSagaReservation::STATUS_PENDING,
            'inventory.reservation.confirmed.v1' => CheckoutSagaReservation::STATUS_CONFIRMED,
            'inventory.reservation.rejected.v1' => CheckoutSagaReservation::STATUS_REJECTED,
            'inventory.reservation.release.requested.v1' => CheckoutSagaReservation::STATUS_RELEASE_PENDING,
            'inventory.reservation.released.v1' => CheckoutSagaReservation::STATUS_RELEASED,
            'inventory.reservation.release_failed.v1' => CheckoutSagaReservation::STATUS_RELEASE_FAILED,
            default => null,
        };
    }

    private function allows(string $current, string $next): bool
    {
        if ($current === $next) {
            return true;
        }

        return in_array($next, match ($current) {
            CheckoutSagaReservation::STATUS_PENDING => [
                CheckoutSagaReservation::STATUS_CONFIRMED,
                CheckoutSagaReservation::STATUS_REJECTED,
            ],
            CheckoutSagaReservation::STATUS_CONFIRMED => [
                CheckoutSagaReservation::STATUS_RELEASE_PENDING,
                CheckoutSagaReservation::STATUS_RELEASED,
                CheckoutSagaReservation::STATUS_RELEASE_FAILED,
            ],
            CheckoutSagaReservation::STATUS_RELEASE_PENDING => [
                CheckoutSagaReservation::STATUS_RELEASED,
                CheckoutSagaReservation::STATUS_RELEASE_FAILED,
            ],
            default => [],
        }, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(string $routingKey, array $payload): ?string
    {
        if (! in_array($routingKey, [
            'inventory.reservation.rejected.v1',
            'inventory.reservation.release_failed.v1',
        ], true)) {
            return null;
        }

        return isset($payload['reason']) ? (string) $payload['reason'] : null;
    }
}
