<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\BriefState;
use App\Models\Brief;
use Throwable;

trait FailsBriefOnJobFailure
{
  /**
   * Handle job failure by transitioning the brief to Failed state.
   */
  public function failed(?Throwable $exception): void
  {
    $brief = Brief::find($this->briefId);

    if ($brief && $brief->state->canTransitionTo(BriefState::Failed))
    {
      $brief->transitionTo(BriefState::Failed);
    }
  }
}
