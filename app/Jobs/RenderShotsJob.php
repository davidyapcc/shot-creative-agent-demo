<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Services\ShotVideoSubmitter;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class RenderShotsJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
    $this->onQueue('generate');
  }

  public function handle(VideoProviderInterface $videoProvider): void
  {
    $brief = Brief::with('shots')->findOrFail($this->briefId);
    $submitter = app(ShotVideoSubmitter::class);

    $pollJobs = [];

    foreach ($brief->shots as $shot)
    {
      if (!$shot->state->canTransitionTo(ShotState::GeneratingVideo))
      {
        continue;
      }

      $poll = $submitter->submit($shot, $videoProvider);

      if ($poll !== null)
      {
        $pollJobs[] = $poll;
      }
    }

    if (empty($pollJobs))
    {
      return;
    }

    Bus::batch($pollJobs)
      ->name("render-brief-{$brief->id}")
      ->then(new AdvanceBriefToAudioCallback($brief->id))
      ->catch(new FailBriefOnBatchFailureCallback($brief->id))
      ->dispatch();
  }
}
