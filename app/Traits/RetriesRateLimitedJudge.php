<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\ProviderRateLimitedException;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

trait RetriesRateLimitedJudge
{
    public const RATE_LIMITER = 'judge';

    private const RETRY_WINDOW_HOURS = 2;

    private const THROTTLE_MAX_EXCEPTIONS = 3;

    private const THROTTLE_DECAY_SECONDS = 300;

    private const THROTTLE_BACKOFF_MINUTES = 1;

    /**
     * Seconds one judgement may run. It stays under the queue's 90s retry_after
     * so the queue never hands a running judgement to a second worker, and above
     * the ffmpeg and HTTP timeouts a video judgement can spend back to back.
     */
    public int $timeout = 85;

    /**
     * A timed-out run already took its charge, so a retry would pay twice.
     * Fail it instead.
     */
    public bool $failOnTimeout = true;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited(self::RATE_LIMITER),
            (new ThrottlesExceptions(self::THROTTLE_MAX_EXCEPTIONS, self::THROTTLE_DECAY_SECONDS))
                ->backoff(self::THROTTLE_BACKOFF_MINUTES)
                ->failWhen(fn (Throwable $exception): bool => ! $exception instanceof ProviderRateLimitedException),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    /**
     * A refused request spent no quota, so give the charge back and count the 429.
     */
    protected function releaseRateLimitedJudge(
        Shot $shot,
        Budget $budget,
        RateLimitHitCounter $rateLimits,
        string $operation,
    ): void {
        $budget->refundShotStep($shot, 'judge', $operation);

        $rateLimits->increment('judge');
    }
}
