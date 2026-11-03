<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;
use Throwable;

trait FailsBriefOnJobFailure
{
  /**
   * Handle job failure by failing the brief and any shot still in flight.
   */
  public function failed(?Throwable $exception): void
  {
    $brief = Brief::find($this->briefId);

    if (!$brief || !$brief->state->canTransitionTo(BriefState::Failed))
    {
      return;
    }

    DB::transaction(function () use ($brief): void
    {
      $brief->transitionTo(BriefState::Failed);

      $brief->shots
        ->filter(fn (Shot $shot) => $shot->state->canTransitionTo(ShotState::Failed))
        ->each(fn (Shot $shot) => $shot->transitionTo(ShotState::Failed));
    });
  }
}
