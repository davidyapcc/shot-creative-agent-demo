<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use Illuminate\Bus\Batch;

class AdvanceBriefToAudioJob
{
  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function __invoke(Batch $batch): void
  {
    $brief = Brief::find($this->briefId);

    if ($brief !== null && $brief->state === BriefState::Rendering)
    {
      $brief->transitionTo(BriefState::Audio);
      GenerateAudioJob::dispatch($this->briefId);
    }
  }
}
