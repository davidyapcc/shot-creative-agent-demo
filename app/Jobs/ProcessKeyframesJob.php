<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Judgement;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessKeyframesJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $briefId,
  )
  {
    $this->onQueue('generate');
  }

  public function handle(?ImageProviderInterface $imageProvider = null, ?JudgeInterface $judge = null): void
  {
    $imageProvider ??= app(ImageProviderInterface::class);
    $judge ??= app(JudgeInterface::class);

    $brief = Brief::with('shots')->findOrFail($this->briefId);

    foreach ($brief->shots as $shot)
    {
      $shot->transitionTo(ShotState::GeneratingImage);

      $imageBytes = $imageProvider->generateImage($shot->prompt, $brief->style_anchor);
      $keyframePath = "keyframes/brief_{$brief->id}_shot_{$shot->id}.png";

      Storage::disk('s3')->put($keyframePath, $imageBytes);

      $shot->update([
        'keyframe_path' => $keyframePath,
      ]);

      $shot->transitionTo(ShotState::JudgingImage);

      $verdict = $judge->judgeImage($keyframePath, $shot->prompt, $brief->style_anchor);

      Judgement::create([
        'shot_id' => $shot->id,
        'stage' => 'image',
        'scores' => $verdict->scores,
        'hard_fail_tags' => $verdict->hardFailTags,
        'reason' => $verdict->reason,
        'verdict' => $verdict->verdict(),
      ]);
    }

    $brief->transitionTo(BriefState::Rendering);
    RenderShotsJob::dispatch($brief->id);
  }
}
