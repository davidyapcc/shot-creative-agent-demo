<?php

declare(strict_types=1);

namespace App\Traits;

use App\DTOs\JudgementResult;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\PaidCall;
use App\Services\ShotStepRetry;
use Closure;
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
     * Pay for one judgement and record its verdict, unless it comes back late.
     * A pass moves the shot to $onPass when one is given; a fail hands the
     * shot to the retry.
     *
     * @param  Closure(): JudgementResult  $judge
     */
    protected function judgeShot(
        Shot $shot,
        JudgementStage $stage,
        string $operation,
        Closure $judge,
        PaidCall $paidCall,
        ShotStepRetry $retry,
        ?ShotState $onPass = null,
    ): void {
        $result = $paidCall->forShot(
            $shot,
            'judge',
            $operation,
            call: $judge,
            apply: function (Shot $locked, JudgementResult $verdict) use ($stage, $onPass): array {
                $judgement = Judgement::create($verdict->toModelAttributes($locked->id, $stage));

                if ($verdict->isPass() && $onPass !== null) {
                    $locked->transitionTo($onPass);
                }

                return [$verdict, $judgement];
            },
        );

        if (! $result->wasApplied()) {
            return;
        }

        [$verdict, $judgement] = $result->value;

        event(JudgeVerdictRecorded::fromVerdict($shot->brief_id, $shot->id, $stage, $verdict));
        ShotUpdated::dispatch($shot);

        if ($verdict->isFail()) {
            $retry->afterFailure($shot, $judgement, $stage, $this->batch());
        }
    }
}
