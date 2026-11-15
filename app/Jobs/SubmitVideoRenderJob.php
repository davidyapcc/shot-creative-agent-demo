<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Shot;
use App\Services\ShotVideoSubmitter;
use App\Traits\FailsBriefOnJobFailure;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * One shot's render submit, first or after a rewrite. It runs on its own so a
 * 429 releases only this submit for a later retry: the other shots' submits
 * and polls carry on, and the brief is not failed.
 */
class SubmitVideoRenderJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;

    public const RATE_LIMITER = 'video';

    private const RETRY_WINDOW_HOURS = 2;

    private const THROTTLE_MAX_EXCEPTIONS = 3;

    private const THROTTLE_DECAY_SECONDS = 300;

    private const THROTTLE_BACKOFF_MINUTES = 1;

    /**
     * A timed-out run already took its charge, so a retry would pay twice.
     * Fail it instead.
     */
    public bool $failOnTimeout = true;

    /**
     * @param  int  $priorVideoAttempts  the shot's video generations when this submit was queued; it names the submit for its claim
     */
    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
        public readonly int $priorVideoAttempts,
    ) {
        $this->onQueue('generate');
    }

    public static function forShot(Shot $shot): self
    {
        return new self($shot->id, $shot->brief_id, $shot->stepAttempts('video'));
    }

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

    public function handle(VideoProviderInterface $videos, ShotVideoSubmitter $submitter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::find($this->shotId);

        if ($shot === null) {
            return;
        }

        $poll = $submitter->submit($shot, $videos, $this->priorVideoAttempts);

        if ($poll === null) {
            return;
        }

        if ($this->batch() !== null) {
            $this->batch()->add([$poll]);

            return;
        }

        dispatch($poll);
    }
}
