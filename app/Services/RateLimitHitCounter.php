<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Counts provider 429s by step. The cache store keeps the total across retries
 * so a later telemetry read can show it.
 */
class RateLimitHitCounter
{
    public function increment(string $step): void
    {
        Cache::increment($this->key($step));
    }

    public function count(string $step): int
    {
        return (int) Cache::get($this->key($step), 0);
    }

    private function key(string $step): string
    {
        return 'rate_limit_hits.'.$step;
    }
}
