<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AudioProviderInterface;
use App\Enums\BriefState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    if ($brief->audio_path !== null)
    {
      return;
    }

    $audio = $audioProvider->generate($brief->prompt, $brief->style_anchor);

    $voiceoverPath = "audio/brief_{$brief->id}_voiceover.wav";
    $soundEffectPath = "audio/brief_{$brief->id}_sfx.wav";

    Storage::disk('s3')->put($voiceoverPath, $audio->voiceover);
    Storage::disk('s3')->put($soundEffectPath, $audio->soundEffect);

    $brief->update([
      'audio_path' => $voiceoverPath,
      'metadata' => array_merge($brief->metadata ?? [], [
        'sound_effect_path' => $soundEffectPath,
      ]),
    ]);

    $brief->transitionTo(BriefState::Muxing);
    MuxFinalVideoJob::dispatch($brief->id);
  }
}
