<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Services\RateLimitHitCounter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class JudgeRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_judge_jobs_retry_rate_limits_for_two_hours_and_fail_other_errors(): void
    {
        config(['creative.judge.requests_per_minute' => 4]);

        $limit = RateLimiter::limiter(JudgeKeyframeJob::RATE_LIMITER);
        $this->assertNotNull($limit);
        $resolved = $limit();
        $this->assertSame(4, $resolved->maxAttempts);
        $this->assertSame(60, $resolved->decaySeconds);

        foreach ([JudgeKeyframeJob::class, JudgeVideoJob::class] as $class) {
            $job = new $class(1, 1);

            $this->assertSame('judge', $class::RATE_LIMITER);
            $this->assertTrue($job->failOnTimeout);
            $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
            $middleware = $job->middleware();
            $this->assertCount(2, $middleware);

            $limited = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof RateLimited);
            $throttle = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
            $this->assertInstanceOf(RateLimited::class, $limited);
            $this->assertInstanceOf(ThrottlesExceptions::class, $throttle);
            $this->assertSame('judge', (new ReflectionProperty($limited, 'limiterName'))->getValue($limited));
            $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

            $released = (new $class(1, 1))->withFakeQueueInteractions();
            $this->throttle($released)->handle($released, function (): void {
                throw new ProviderRateLimitedException('Gemini judge returned HTTP 429.');
            });
            $released->assertReleased();
            $released->assertNotFailed();

            $failed = (new $class(1, 1))->withFakeQueueInteractions();
            $this->throttle($failed)->handle($failed, function (): void {
                throw new RuntimeException('Gemini judge returned HTTP 500.');
            });
            $failed->assertFailed();
            $failed->assertNotReleased();
        }
    }

    public function test_rate_limit_hits_survive_a_cache_flush(): void
    {
        // Arrange
        $counter = app(RateLimitHitCounter::class);
        $counter->increment('judge');
        $counter->increment('judge');

        // Act
        Cache::flush();

        // Assert
        $this->assertSame(2, $counter->count('judge'));
        $this->assertSame(0, $counter->count('audio'));
    }

    private function throttle(ShouldQueue $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
    }
}
