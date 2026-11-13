<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Traits\FailsBriefOnJobFailure;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateKeyframeJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;

    public const RATE_LIMITER = 'image';

    private const RETRY_WINDOW_HOURS = 2;

    private const THROTTLE_MAX_EXCEPTIONS = 3;

    private const THROTTLE_DECAY_SECONDS = 300;

    private const THROTTLE_BACKOFF_MINUTES = 1;

    /**
     * A timed-out run already took its charge, so a retry would pay twice.
     * Fail it instead.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
    ) {
        $this->onQueue('generate');
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

    public function handle(
        ImageProviderInterface $imageProvider,
        Budget $budget,
        ?RateLimitHitCounter $rateLimits = null,
    ): void {
        $rateLimits ??= new RateLimitHitCounter;

        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        if (! $budget->chargeShotStep($shot, 'image', 'generateImage')) {
            return;
        }

        if ($shot->state->canTransitionTo(ShotState::GeneratingImage)) {
            $shot->transitionTo(ShotState::GeneratingImage);
            ShotUpdated::dispatch($shot);
        }

        try {
            $imageBytes = $imageProvider->generateImage($shot->prompt, $shot->brief?->style_anchor);
        } catch (ProviderRateLimitedException $exception) {
            // A refused request spent no quota, so refund the charge without counting an attempt.
            $budget->refundShotStep($shot, 'image', 'generateImage');
            $rateLimits->increment('image');

            throw $exception;
        } catch (Throwable $exception) {
            // Any other failure keeps its charge, so it counts as a generation (shots.attempts counts every one).
            $shot->recordStepAttempt('image');

            throw $exception;
        }

        $shot->recordStepAttempt('image');

        $keyframePath = "keyframes/brief_{$shot->brief_id}_shot_{$shot->id}.png";

        Storage::disk('s3')->put($keyframePath, $imageBytes);

        $shot->update([
            'keyframe_path' => $keyframePath,
        ]);

        if (! $shot->advanceUnlessStopped(ShotState::JudgingImage)) {
            return;
        }

        ShotUpdated::dispatch($shot);
    }
}
