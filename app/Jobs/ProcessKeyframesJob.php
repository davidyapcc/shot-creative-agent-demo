<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessKeyframesJob implements ShouldQueue
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
      $shot->transitionTo(ShotState::GeneratingImage);
      $shot->update([
        'keyframe_path' => "keyframes/shot_{$shot->id}_keyframe.png",
      ]);
      $shot->transitionTo(ShotState::JudgingImage);
    }

    $brief->transitionTo(BriefState::Rendering);
    RenderShotsJob::dispatch($brief->id);
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
