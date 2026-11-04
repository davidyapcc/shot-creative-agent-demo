<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\JudgementResult;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class JudgeVerdictRecorded implements ShouldBroadcastNow
{
  use Dispatchable;

  /**
   * @param array<int, string> $hardFailTags
   */
  public function __construct(
    public readonly int $briefId,
    public readonly int $shotId,
    public readonly JudgementStage $stage,
    public readonly JudgementVerdict $verdict,
    public readonly array $hardFailTags = [],
    public readonly ?string $reason = null,
  )
  {
  }

  public static function fromVerdict(
    int $briefId,
    int $shotId,
    JudgementStage $stage,
    JudgementResult $verdict,
  ): self
  {
    return new self(
      briefId: $briefId,
      shotId: $shotId,
      stage: $stage,
      verdict: $verdict->verdict(),
      hardFailTags: $verdict->hardFailTags,
      reason: $verdict->reason,
    );
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
    return 'JudgeVerdictRecorded';
  }

  /**
   * @return array<string, mixed>
   */
  public function broadcastWith(): array
  {
    return [
      'briefId' => $this->briefId,
      'shotId' => $this->shotId,
      'stage' => $this->stage->value,
      'verdict' => $this->verdict->value,
      'hardFailTags' => $this->hardFailTags,
      'reason' => $this->reason,
    ];
  }
}
