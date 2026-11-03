<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MuxFinalVideoJob implements ShouldQueue
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
      'final_video_path' => "final/brief_{$brief->id}_final.mp4",
    ]);

    $brief->transitionTo(BriefState::Done);
  }
}
