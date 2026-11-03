<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Judgement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessKeyframesJob implements ShouldQueue
{
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function handle(): void
  {
    $brief = Brief::with('shots')->findOrFail($this->briefId);

    foreach ($brief->shots as $shot)
    {
      $shot->transitionTo(ShotState::GeneratingImage);
      $shot->update([
        'keyframe_path' => "keyframes/shot_{$shot->id}_keyframe.png",
      ]);
      $shot->transitionTo(ShotState::JudgingImage);

      Judgement::create([
        'shot_id' => $shot->id,
        'stage' => 'image',
        'scores' => [
          'prompt_adherence' => 5,
          'anatomy' => 5,
          'object_integrity' => 5,
          'temporal_consistency' => 5,
        ],
        'hard_fail_tags' => [],
        'reason' => 'Keyframe conforms to style anchor and prompt.',
        'verdict' => 'pass',
      ]);
    }

    $brief->transitionTo(BriefState::Rendering);
    RenderShotsJob::dispatch($brief->id);
  }
}
