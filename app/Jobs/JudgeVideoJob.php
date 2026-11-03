<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\JudgeInterface;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Models\Judgement;
use App\Models\Shot;
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

  public function handle(JudgeInterface $judge): void
  {
    if ($this->batch()?->cancelled())
    {
      return;
    }

    $shot = Shot::with('brief')->findOrFail($this->shotId);

    $verdict = $judge->judgeVideo(
      (string) $shot->video_path,
      $shot->prompt,
      $shot->brief?->style_anchor,
    );

    Judgement::create($verdict->toModelAttributes($shot->id, JudgementStage::Video));

    $tag = $verdict->hardFailTags[0] ?? null;

    JudgeVerdictRecorded::dispatch(
      briefId: $this->briefId,
      shotId: $this->shotId,
      stage: 'video',
      verdict: $verdict->isPass() ? 'pass' : 'fail',
      tag: $tag,
      hardFailTags: $verdict->hardFailTags,
      reason: $verdict->reason,
    );

    if ($verdict->isPass())
    {
      $shot->transitionTo(ShotState::Done);
      ShotUpdated::dispatch($shot);
    }
    else
    {
      ShotUpdated::dispatch($shot->refresh());
      $shot->brief?->failWithInFlightShots();
      $this->batch()?->cancel();
    }
  }
}
