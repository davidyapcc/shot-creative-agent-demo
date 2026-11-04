<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioProviderInterface;
use App\Enums\BriefState;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Services\Budget;
use App\Traits\FailsBriefOnJobFailure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
  )
  {
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
        ->failWhen(fn (Throwable $exception): bool => !$exception instanceof ProviderRateLimitedException),
    ];
  }

  public function retryUntil(): DateTimeInterface
  {
    return now()->addHours(self::RETRY_WINDOW_HOURS);
  }

  public function handle(AudioProviderInterface $audioProvider): void
  {
    $brief = Brief::findOrFail($this->briefId);

    if ($brief->metadata['budget_exhausted'] ?? false)
    {
      return;
    }

    if ($brief->audio_path !== null || $brief->state !== BriefState::Audio)
    {
      return;
    }

    $budget = app(Budget::class);

    if (!$budget->chargeOrDegrade($brief, 'audio', 'generate'))
    {
      return;
    }

    $voiceoverPath = "audio/brief_{$brief->id}_voiceover.wav";
    $soundEffectPath = "audio/brief_{$brief->id}_sfx.wav";

    if (!$this->claim($brief->id, $voiceoverPath, $soundEffectPath))
    {
      $budget->refund($brief, 'audio', 'generate');

      return;
    }

    try
    {
      $audio = $audioProvider->generate($brief->prompt, $brief->style_anchor);
    }
    catch (ProviderRateLimitedException $exception)
    {
      // A refused request spent no quota, so give the claim and the charge back.
      $budget->refund($brief, 'audio', 'generate');
      $this->releaseClaim($brief->id);

      throw $exception;
    }

    Storage::disk('s3')->put($voiceoverPath, $audio->voiceover);
    Storage::disk('s3')->put($soundEffectPath, $audio->soundEffect);

    $brief->refresh();
    $brief->transitionTo(BriefState::Muxing);
    MuxFinalVideoJob::dispatch($brief->id);
  }

  /**
   * Reserve the audio paths under a row lock so only one run calls the provider.
   */
  private function claim(int $briefId, string $voiceoverPath, string $soundEffectPath): bool
  {
    return DB::transaction(function () use ($briefId, $voiceoverPath, $soundEffectPath): bool
    {
      $locked = Brief::query()->whereKey($briefId)->lockForUpdate()->first();

      if ($locked === null || $locked->audio_path !== null || $locked->state !== BriefState::Audio)
      {
        return false;
      }

      $locked->update([
        'audio_path' => $voiceoverPath,
        'metadata' => array_merge($locked->metadata ?? [], [
          'sound_effect_path' => $soundEffectPath,
        ]),
      ]);

      return true;
    });
  }

  private function releaseClaim(int $briefId): void
  {
    DB::transaction(function () use ($briefId): void
    {
      $locked = Brief::query()->whereKey($briefId)->lockForUpdate()->firstOrFail();

      $locked->update([
        'audio_path' => null,
        'metadata' => Arr::except($locked->metadata ?? [], ['sound_effect_path']),
      ]);
    });
  }
}
