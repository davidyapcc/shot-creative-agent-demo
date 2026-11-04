<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Contracts\JudgeInterface;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class JudgeVideoJob implements ShouldQueue
{
  use Batchable;
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $shotId,
    public readonly int $briefId,
  )
  {
    $this->onQueue('judge');
  }

  public function handle(JudgeInterface $judge, AgentInterface $agent): void
  {
    if ($this->batch()?->cancelled())
    {
      return;
    }

    $shot = Shot::with('brief')->findOrFail($this->shotId);

    if ($shot->state === ShotState::Failed || ($shot->brief?->metadata['budget_exhausted'] ?? false))
    {
      return;
    }

    if ($shot->brief !== null && !app(Budget::class)->chargeOrDegrade($shot->brief, 'judge', 'judgeVideo'))
    {
      return;
    }

    $shot->refresh();

    if ($shot->state === ShotState::Failed)
    {
      return;
    }

    $verdict = $judge->judgeVideo(
      (string) $shot->video_path,
      $shot->prompt,
      $shot->brief?->style_anchor,
    );

    Judgement::create($verdict->toModelAttributes($shot->id, JudgementStage::Video));

    event(JudgeVerdictRecorded::fromVerdict(
      $this->briefId,
      $this->shotId,
      JudgementStage::Video,
      $verdict,
    ));

    if ($verdict->isPass())
    {
      $shot->transitionTo(ShotState::Done);
      ShotUpdated::dispatch($shot);
    }
    else
    {
      ShotUpdated::dispatch($shot->refresh());
      (new ShotStepRetry($agent))->afterFailure($shot, $verdict, JudgementStage::Video, $this->batch());
    }
  }
}
