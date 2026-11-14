<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioProviderInterface;
use App\Enums\BriefState;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Services\PaidCall;
use App\Services\PaidCallClaim;
use App\Services\PaidCallOutcome;
use App\Traits\FailsBriefOnJobFailure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateAudioJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;

    public const RATE_LIMITER = 'audio';

    private const RETRY_WINDOW_HOURS = 2;

    private const THROTTLE_MAX_EXCEPTIONS = 3;

    private const THROTTLE_DECAY_SECONDS = 300;

    private const THROTTLE_BACKOFF_MINUTES = 1;

    /**
     * A timed-out run may already hold the audio claim, so a retry could only
     * return early and leave the brief in Audio. Fail it instead.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $briefId,
    ) {
        $this->onQueue('audio');
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

    public function handle(AudioProviderInterface $audioProvider, PaidCall $paidCall): void
    {
        $brief = Brief::findOrFail($this->briefId);

        if ($brief->isBudgetExhausted()) {
            return;
        }

        if ($brief->audio_path !== null || $brief->state !== BriefState::Audio) {
            return;
        }

        $voiceoverPath = "audio/brief_{$brief->id}_voiceover.wav";
        $soundEffectPath = "audio/brief_{$brief->id}_sfx.wav";

        $result = $paidCall->forBrief(
            $brief,
            BriefState::Audio,
            'audio',
            'generate',
            call: function () use ($audioProvider, $brief, $voiceoverPath, $soundEffectPath): void {
                $audio = $audioProvider->generate($brief->prompt, $brief->style_anchor);

                Storage::disk('s3')->put($voiceoverPath, $audio->voiceover);
                Storage::disk('s3')->put($soundEffectPath, $audio->soundEffect);
            },
            apply: fn (Brief $locked) => $locked->transitionTo(BriefState::Muxing),
            claim: $this->claim($voiceoverPath, $soundEffectPath),
        );

        if ($result->outcome === PaidCallOutcome::Late) {
            // The claim is released, so nothing points at these files any more.
            Storage::disk('s3')->delete([$voiceoverPath, $soundEffectPath]);
        }

        if ($result->wasApplied()) {
            MuxFinalVideoJob::dispatch($brief->id);
        }
    }

    /**
     * The claim is the reserved audio paths: only the run that set them calls the provider.
     */
    private function claim(string $voiceoverPath, string $soundEffectPath): PaidCallClaim
    {
        return new PaidCallClaim(
            take: function (Brief $locked) use ($voiceoverPath, $soundEffectPath): bool {
                if ($locked->audio_path !== null) {
                    return false;
                }

                $locked->update([
                    'audio_path' => $voiceoverPath,
                    'metadata' => [...$locked->metadata ?? [], 'sound_effect_path' => $soundEffectPath],
                ]);

                return true;
            },
            release: function (Brief $locked): void {
                $locked->update([
                    'audio_path' => null,
                    'metadata' => Arr::except($locked->metadata ?? [], ['sound_effect_path']),
                ]);
            },
        );
    }
}
