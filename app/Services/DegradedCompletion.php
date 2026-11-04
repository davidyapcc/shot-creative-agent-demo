<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\MuxFinalVideoJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Support\Facades\DB;

class DegradedCompletion
{
  /**
   * Stop provider work and finish on the clips that already passed.
   */
  public function finish(Brief $brief): void
  {
    $brief->refresh();

    if (in_array($brief->state, [BriefState::Done, BriefState::Degraded, BriefState::Failed], true))
    {
      return;
    }

    if ($brief->metadata['budget_exhausted'] ?? false)
    {
      return;
    }

    DB::transaction(function () use ($brief): void
    {
      $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();

      if ($locked->metadata['budget_exhausted'] ?? false)
      {
        return;
      }

      $metadata = $locked->metadata ?? [];
      $metadata['budget_exhausted'] = true;
      $locked->update([
        'metadata' => $metadata,
      ]);

      // Lock the shots too, so a late job's advanceUnlessStopped waits for this.
      $locked->shots()->lockForUpdate()->get()->each(function (Shot $shot): void
      {
        if ($shot->state !== ShotState::Done && $shot->state->canTransitionTo(ShotState::Failed))
        {
          $shot->transitionTo(ShotState::Failed);
        }
      });
    });

    $this->muxOrDegrade($brief);
  }

  private function muxOrDegrade(Brief $brief): void
  {
    $brief->refresh();
    $brief->load('shots');

    $passed = $brief->shots->contains(fn (Shot $shot): bool => $shot->state === ShotState::Done
      && is_string($shot->video_path)
      && $shot->video_path !== '');

    if (!$passed)
    {
      if ($brief->state->canTransitionTo(BriefState::Degraded))
      {
        $brief->transitionTo(BriefState::Degraded);
      }

      return;
    }

    if ($brief->state === BriefState::Keyframes)
    {
      $brief->transitionTo(BriefState::Rendering);
    }

    if ($brief->state === BriefState::Rendering)
    {
      $brief->transitionTo(BriefState::Audio);
    }

    if ($brief->state === BriefState::Audio)
    {
      $brief->transitionTo(BriefState::Muxing);
    }

    if ($brief->state === BriefState::Muxing)
    {
      MuxFinalVideoJob::dispatch($brief->id);
    }
  }
}
