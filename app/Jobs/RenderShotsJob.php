<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Models\Brief;
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

    $pollJobs = [];

    foreach ($brief->shots as $shot)
    {
      if ($shot->state->canTransitionTo(ShotState::GeneratingVideo))
      {
        $shot->transitionTo(ShotState::GeneratingVideo);
      }

      $renderId = $videoProvider->submit($shot->prompt, (string) $shot->keyframe_path);

      $shot->update([
        'metadata' => array_merge($shot->metadata ?? [], ['render_id' => $renderId]),
      ]);

      VideoProgressUpdated::dispatch($brief->id, $shot->id, 0);
      ShotUpdated::dispatch($shot);

      $pollJobs[] = new PollVideoRenderJob($shot->id, $renderId, $brief->id);
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
