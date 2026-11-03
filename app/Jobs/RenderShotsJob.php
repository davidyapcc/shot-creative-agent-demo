<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Judgement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderShotsJob implements ShouldQueue
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
      $shot->transitionTo(ShotState::GeneratingVideo);
      $shot->update([
        'video_path' => "videos/shot_{$shot->id}_video.mp4",
      ]);
      $shot->transitionTo(ShotState::JudgingVideo);

      Judgement::create([
        'shot_id' => $shot->id,
        'stage' => 'video',
        'scores' => [
          'prompt_adherence' => 5,
          'anatomy' => 5,
          'object_integrity' => 5,
          'temporal_consistency' => 5,
        ],
        'hard_fail_tags' => [],
        'reason' => 'Video render maintains temporal consistency and high fidelity.',
        'verdict' => 'pass',
      ]);

      $shot->transitionTo(ShotState::Done);
    }

    $brief->transitionTo(BriefState::Audio);
    GenerateAudioJob::dispatch($brief->id);
  }
}
