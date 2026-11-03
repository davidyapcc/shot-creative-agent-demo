<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Shot;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

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

      $pollJobs[] = new PollVideoRenderJob($shot->id, $renderId, $brief->id);
    }

    if (empty($pollJobs))
    {
      return;
    }

    $briefId = $brief->id;

    Bus::batch($pollJobs)
      ->name("render-brief-{$brief->id}")
      ->then(new AdvanceBriefToAudioJob($brief->id))
      ->catch(function (Batch $batch, Throwable $e) use ($briefId): void
      {
        $brief = Brief::find($briefId);
        if ($brief && $brief->state->canTransitionTo(BriefState::Failed))
        {
          $brief->transitionTo(BriefState::Failed);
          $brief->shots
            ->filter(fn (Shot $shot) => $shot->state->canTransitionTo(ShotState::Failed))
            ->each(fn (Shot $shot) => $shot->transitionTo(ShotState::Failed));
        }
      })
      ->dispatch();
  }
}
