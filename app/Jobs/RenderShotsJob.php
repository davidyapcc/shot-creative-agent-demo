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
use Illuminate\Support\Facades\Log;

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
      $this->failWhenNoShotCanRender($brief);

      return;
    }

    Bus::batch($pollJobs)
      ->name("render-brief-{$brief->id}")
      ->then(new AdvanceBriefToAudioCallback($brief->id))
      ->catch(new FailBriefOnBatchFailureCallback($brief->id))
      ->dispatch();
  }

  /**
   * Every shot used up its image retries, so no batch will ever advance the
   * brief. A budget stop is left to DegradedCompletion, which owns that path.
   */
  private function failWhenNoShotCanRender(Brief $brief): void
  {
    $brief->refresh();

    if ($brief->metadata['budget_exhausted'] ?? false)
    {
      return;
    }

    Log::warning('No shot passed its keyframe; failing the brief.', [
      'brief_id' => $brief->id,
    ]);

    $brief->failWithInFlightShots();
  }
}
