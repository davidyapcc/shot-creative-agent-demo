<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAudioJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function handle(): void
  {
    $brief = Brief::findOrFail($this->briefId);
    $brief->update([
      'audio_path' => "audio/brief_{$brief->id}_audio.mp3",
    ]);

    $brief->transitionTo(BriefState::Muxing);
    MuxFinalVideoJob::dispatch($brief->id);
  }
}
