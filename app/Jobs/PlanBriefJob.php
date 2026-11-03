<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PlanBriefJob implements ShouldQueue
{
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function handle(AgentInterface $agent): void
  {
    $brief = Brief::findOrFail($this->briefId);

    $brief->transitionTo(BriefState::Planning);

    $styleAnchor = $agent->generateStyleAnchor($brief->prompt);
    $brief->update([
      'style_anchor' => $styleAnchor,
    ]);

    $plannedShots = $agent->planShots($brief->prompt);

    foreach ($plannedShots as $shotData)
    {
      Shot::create([
        'brief_id' => $brief->id,
        'sequence' => $shotData['sequence'],
        'prompt' => $shotData['prompt'],
        'state' => ShotState::Pending,
      ]);
    }

    $brief->transitionTo(BriefState::Keyframes);

    ProcessKeyframesJob::dispatch($brief->id);
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
