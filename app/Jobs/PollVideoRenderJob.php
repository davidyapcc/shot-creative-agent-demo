<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Models\Judgement;
use App\Models\Shot;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PollVideoRenderJob implements ShouldQueue
{
  use Batchable;
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $shotId,
    public readonly string $renderId,
    public ?int $briefId = null,
  )
  {
    $this->onQueue('poll');

    if ($this->briefId === null)
    {
      $shot = Shot::find($this->shotId);
      $this->briefId = $shot?->brief_id;
    }
  }

  public function handle(?VideoProviderInterface $videoProvider = null, ?JudgeInterface $judge = null): void
  {
    $videoProvider ??= app(VideoProviderInterface::class);
    $judge ??= app(JudgeInterface::class);

    if ($this->batch()?->cancelled())
    {
      return;
    }

    $result = $videoProvider->poll($this->renderId);

    if ($result->isPending())
    {
      $this->release(10);

      return;
    }

    if ($result->isFailed())
    {
      throw new RuntimeException('Video render failed: ' . ($result->error ?? 'unknown error'));
    }

    $shot = Shot::with('brief')->findOrFail($this->shotId);

    $videoPath = "videos/brief_{$shot->brief_id}_shot_{$shot->id}.mp4";

    Storage::disk('s3')->put($videoPath, (string) $result->videoContent);

    $shot->update([
      'video_path' => $videoPath,
    ]);

    $shot->transitionTo(ShotState::JudgingVideo);

    $verdict = $judge->judgeVideo($videoPath, $shot->prompt, $shot->brief?->style_anchor);

    Judgement::create([
      'shot_id' => $shot->id,
      'stage' => 'video',
      'scores' => $verdict->scores,
      'hard_fail_tags' => $verdict->hardFailTags,
      'reason' => $verdict->reason,
      'verdict' => $verdict->verdict(),
    ]);

    $shot->transitionTo(ShotState::Done);
  }
}
