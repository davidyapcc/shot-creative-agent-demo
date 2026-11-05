<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RateLimitHitCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitHitCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_sums_hits_across_steps_and_is_zero_when_the_table_is_empty(): void
    {
        $counter = app(RateLimitHitCounter::class);

        $this->assertSame(0, $counter->total());

        $counter->increment('judge');
        $counter->increment('judge');
        $counter->increment('audio');

        $this->assertSame(3, $counter->total());
    }
}
