<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Brief;
use Throwable;

trait FailsBriefOnJobFailure
{
  /**
   * Handle job failure by failing the brief and any shot still in flight.
   */
  public function failed(?Throwable $exception): void
  {
    $brief = Brief::find($this->briefId);

    $brief?->failWithInFlightShots();
  }
}
