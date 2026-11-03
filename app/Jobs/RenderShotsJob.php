<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenderShotsJob implements ShouldQueue
{
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function handle(): void
  {
    $brief = Brief::with('shots')->findOrFail($this->briefId);

    foreach ($brief->shots as $shot)
    {
      $shot->transitionTo(ShotState::GeneratingVideo);
      $shot->update([
        'video_path' => "videos/shot_{$shot->id}_video.mp4",
      ]);
      $shot->transitionTo(ShotState::JudgingVideo);
      $shot->transitionTo(ShotState::Done);
    }

    $brief->transitionTo(BriefState::Audio);
    GenerateAudioJob::dispatch($brief->id);
  }

  public function failed(?Throwable $exception): void
  {
    $brief = Brief::find($this->briefId);

    if ($brief && $brief->state->canTransitionTo(BriefState::Failed))
    {
      $brief->transitionTo(BriefState::Failed);
    }
  }
}
