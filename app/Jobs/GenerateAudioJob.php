<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioProviderInterface;
use App\Enums\BriefState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GenerateAudioJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
    $this->onQueue('audio');
  }

  public function handle(AudioProviderInterface $audioProvider): void
  {
    $brief = Brief::findOrFail($this->briefId);

    if ($brief->audio_path !== null || $brief->state !== BriefState::Audio)
    {
      return;
    }

    $voiceoverPath = "audio/brief_{$brief->id}_voiceover.wav";
    $soundEffectPath = "audio/brief_{$brief->id}_sfx.wav";

    $claimed = DB::transaction(function () use ($brief, $voiceoverPath, $soundEffectPath): bool
    {
      $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->first();

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

    if (!$claimed)
    {
      return;
    }

    $audio = $audioProvider->generate($brief->prompt, $brief->style_anchor);

    Storage::disk('s3')->put($voiceoverPath, $audio->voiceover);
    Storage::disk('s3')->put($soundEffectPath, $audio->soundEffect);

    $brief->refresh();
    $brief->transitionTo(BriefState::Muxing);
    MuxFinalVideoJob::dispatch($brief->id);
  }
}
