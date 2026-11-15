<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Shot;
use App\Services\PaidCall;
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

    public function handle(ImageProviderInterface $imageProvider, PaidCall $paidCall): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        $result = $paidCall->forShot(
            $shot,
            'image',
            'generateImage',
            call: fn (): string => $this->generate($shot, $imageProvider),
            apply: function (Shot $locked, string $keyframePath): void {
                $locked->recordStepAttempt('image');
                $locked->update(['keyframe_path' => $keyframePath]);
                $locked->transitionTo(ShotState::JudgingImage);
            },
            // A failure that kept its charge is a generation (shots.attempts counts every one).
            onFailure: fn (Shot $locked) => $locked->recordStepAttempt('image'),
        );

        if ($result->wasApplied()) {
            ShotUpdated::dispatch($shot);
        }
    }

    /**
     * Generate the keyframe and upload it. Returns the stored path.
     */
    private function generate(Shot $shot, ImageProviderInterface $imageProvider): string
    {
        // Locked, so a budget stop that failed the shot since the charge is never overwritten.
        if ($shot->advanceIfAllowed(ShotState::GeneratingImage)) {
            ShotUpdated::dispatch($shot);
        }

        $imageBytes = $imageProvider->generateImage($shot->prompt, $shot->brief?->style_anchor);

        $keyframePath = "keyframes/brief_{$shot->brief_id}_shot_{$shot->id}.png";

        Storage::disk('s3')->put($keyframePath, $imageBytes);

        return $keyframePath;
    }
}
