<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JudgeInterface;
use App\DTOs\EvalClipResult;
use App\DTOs\GoldenClip;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\JudgeVideoJob;
use App\Models\EvalRun;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Judges golden clips for one eval run, charging the run's budget before each
 * call and sharing the pipeline's judge rate limiter.
 */
class MeteredClipJudge
{
    /** Attempts per clip in total, so a clip errors after this many 429s. */
    public const MAX_ATTEMPTS = 3;

    /** Pause after a 429 before the next attempt. */
    public const RATE_LIMIT_BACKOFF_SECONDS = 10;

    private const OPERATION = 'judgeVideo';

    public function __construct(
        private readonly JudgeInterface $judge,
        private readonly EvalBudget $budget,
        private readonly EvalRun $run,
        private readonly int $cap,
        private readonly string $provider,
    ) {}

    /**
     * Null means the budget is spent and the run should stop.
     */
    public function judge(GoldenClip $clip): ?EvalClipResult
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            if (! $this->budget->charge($this->run, $this->cap, $this->provider, self::OPERATION, $clip->id)) {
                return null;
            }

            $this->waitForJudgeSlot();

            try {
                return EvalClipResult::judged($clip, $this->judge->judgeVideo($clip->path, $clip->prompt));
            } catch (ProviderRateLimitedException $exception) {
                // A refused request spent no quota.
                $this->budget->refund($this->run, $this->provider, self::OPERATION, $clip->id);

                if ($attempt === self::MAX_ATTEMPTS) {
                    return EvalClipResult::errored($clip, $exception->getMessage());
                }

                Sleep::for(self::RATE_LIMIT_BACKOFF_SECONDS)->seconds();
            } catch (Throwable $exception) {
                return EvalClipResult::errored($clip, $exception->getMessage());
            }
        }
    }

    private function waitForJudgeSlot(): void
    {
        $name = JudgeVideoJob::RATE_LIMITER;
        $limiter = RateLimiter::limiter($name);
        $limit = $limiter === null ? null : $limiter();

        if ($limit === null || $limit instanceof Unlimited) {
            return;
        }

        $key = md5($name.$limit->key);

        while (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            Sleep::for(max(1, RateLimiter::availableIn($key)))->seconds();
        }

        RateLimiter::hit($key, $limit->decaySeconds);
    }
}
