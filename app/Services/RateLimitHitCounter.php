<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Counts provider 429s by step in the database, so the total survives cache
 * flushes and retries for a later telemetry read.
 */
class RateLimitHitCounter
{
    private const TABLE = 'rate_limit_hits';

    public function increment(string $step): void
    {
        $now = now();

        // One upsert, so concurrent increments never lose a hit.
        DB::table(self::TABLE)->upsert(
            [['step' => $step, 'hits' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['step'],
            ['hits' => DB::raw(self::TABLE.'.hits + 1'), 'updated_at' => $now],
        );
    }

    public function count(string $step): int
    {
        return (int) DB::table(self::TABLE)->where('step', $step)->value('hits');
    }

    public function total(): int
    {
        return (int) DB::table(self::TABLE)->sum('hits');
    }
}
