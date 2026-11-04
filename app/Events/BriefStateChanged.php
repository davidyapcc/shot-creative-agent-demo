<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\BriefState;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class BriefStateChanged implements ShouldBroadcastNow
{
  use Dispatchable;

  public function __construct(
    public readonly int $briefId,
    public readonly BriefState $from,
    public readonly BriefState $to,
  )
  {
  }

  /**
   * @return array<int, Channel>
   */
  public function broadcastOn(): array
  {
    return [
      new Channel("briefs.{$this->briefId}"),
    ];
  }

  public function broadcastAs(): string
  {
    return 'BriefStateChanged';
  }

  /**
   * @return array<string, mixed>
   */
  public function broadcastWith(): array
  {
    return [
      'briefId' => $this->briefId,
      'from' => $this->from->value,
      'to' => $this->to->value,
    ];
  }
}
