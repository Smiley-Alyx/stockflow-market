<?php

namespace App\Infrastructure\Resilience;

use Illuminate\Contracts\Cache\Repository;

class CircuitBreaker
{
    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function allows(string $dependency): bool
    {
        return ! $this->isOpen($dependency);
    }

    public function isOpen(string $dependency): bool
    {
        $openedUntil = (int) $this->cache->get($this->key($dependency, 'opened_until'), 0);

        if ($openedUntil <= 0) {
            return false;
        }

        if ($openedUntil <= now()->getTimestamp()) {
            $this->reset($dependency);

            return false;
        }

        return true;
    }

    public function recordSuccess(string $dependency): void
    {
        $this->reset($dependency);
    }

    public function recordFailure(string $dependency): void
    {
        $this->cache->add(
            $this->key($dependency, 'failures'),
            0,
            $this->failureWindowSeconds($dependency),
        );

        $failures = $this->cache->increment($this->key($dependency, 'failures'));
        $this->cache->put(
            $this->key($dependency, 'failures'),
            $failures,
            $this->failureWindowSeconds($dependency),
        );

        if ($failures >= $this->failureThreshold($dependency)) {
            $this->open($dependency);
        }
    }

    public function open(string $dependency): void
    {
        $this->cache->put(
            $this->key($dependency, 'opened_until'),
            now()->addSeconds($this->openSeconds($dependency))->getTimestamp(),
            $this->openSeconds($dependency),
        );
    }

    public function reset(string $dependency): void
    {
        $this->cache->forget($this->key($dependency, 'failures'));
        $this->cache->forget($this->key($dependency, 'opened_until'));
    }

    private function failureThreshold(string $dependency): int
    {
        return max(1, (int) config("stockflow.circuit_breakers.{$dependency}.failure_threshold", 3));
    }

    private function failureWindowSeconds(string $dependency): int
    {
        return max(1, (int) config("stockflow.circuit_breakers.{$dependency}.failure_window_seconds", 60));
    }

    private function openSeconds(string $dependency): int
    {
        return max(1, (int) config("stockflow.circuit_breakers.{$dependency}.open_seconds", 30));
    }

    private function key(string $dependency, string $suffix): string
    {
        return "stockflow:circuit-breakers:{$dependency}:{$suffix}";
    }
}
