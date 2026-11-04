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

    if ($shot->state === ShotState::Failed || $shot->brief === null)
    {
      return null;
    }

    if ($shot->brief->metadata['budget_exhausted'] ?? false)
    {
      return null;
    }

    if (!$this->budget->chargeOrDegrade($shot->brief, 'video', 'submit'))
    {
      return null;
    }

    $shot->refresh();

    if ($shot->state === ShotState::Failed)
    {
      return null;
    }

    if ($shot->state->canTransitionTo(ShotState::GeneratingVideo))
    {
      $shot->transitionTo(ShotState::GeneratingVideo);
    }

    $renderId = $videos->submit($shot->prompt, (string) $shot->keyframe_path);

    $metadata = $shot->metadata ?? [];
    $videoAttempts = (int) ($metadata['step_attempts']['video'] ?? 0) + 1;
    $metadata['step_attempts']['video'] = $videoAttempts;
    $metadata['render_id'] = $renderId;

    $shot->update([
      'attempts' => (int) $shot->attempts + 1,
      'metadata' => $metadata,
    ]);

    VideoProgressUpdated::dispatch($shot->brief_id, $shot->id, 0);
    ShotUpdated::dispatch($shot);

    return new PollVideoRenderJob($shot->id, $renderId, $shot->brief_id);
  }
}
