<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MuxFinalVideoJob implements ShouldQueue
{
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
      'final_video_path' => "final/brief_{$brief->id}_final.mp4",
    ]);

    $brief->transitionTo(BriefState::Done);
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
