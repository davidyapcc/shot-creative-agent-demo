<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Jobs\PollVideoRenderJob;
use App\Models\Shot;

class ShotVideoSubmitter
{
  public function __construct(
    private readonly Budget $budget,
  )
  {
  }

  public function submit(Shot $shot, VideoProviderInterface $videos): ?PollVideoRenderJob
  {
    $shot->loadMissing('brief');

    if ($shot->brief === null || !$this->budget->chargeShotStep($shot, 'video', 'submit'))
    {
      return null;
    }

    if ($shot->state->canTransitionTo(ShotState::GeneratingVideo))
    {
      $shot->transitionTo(ShotState::GeneratingVideo);
    }

    $renderId = $videos->submit($shot->prompt, (string) $shot->keyframe_path);

    $shot->recordStepAttempt('video', ['render_id' => $renderId]);

    VideoProgressUpdated::dispatch($shot->brief_id, $shot->id, 0);
    ShotUpdated::dispatch($shot);

    return new PollVideoRenderJob($shot->id, $renderId, $shot->brief_id);
  }
}
