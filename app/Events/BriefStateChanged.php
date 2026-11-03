<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\BriefState;
use Illuminate\Foundation\Events\Dispatchable;

class BriefStateChanged
{
  use Dispatchable;

  public function __construct(
    public readonly int $briefId,
    public readonly BriefState $from,
    public readonly BriefState $to,
  ) {}
}
