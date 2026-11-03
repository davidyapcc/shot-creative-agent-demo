<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProviderInterface;
use App\Enums\ShotState;
use App\Models\Shot;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class GenerateKeyframeJob implements ShouldQueue
{
  use Batchable;
  use FailsBriefOnJobFailure;
  use Queueable;

  public function __construct(
    public readonly int $shotId,
    public readonly int $briefId,
  )
  {
    $this->onQueue('generate');
  }

  public function handle(ImageProviderInterface $imageProvider): void
  {
    if ($this->batch()?->cancelled())
    {
      return;
    }

    $shot = Shot::with('brief')->findOrFail($this->shotId);
    $shot->transitionTo(ShotState::GeneratingImage);

    $imageBytes = $imageProvider->generateImage($shot->prompt, $shot->brief?->style_anchor);
    $keyframePath = "keyframes/brief_{$shot->brief_id}_shot_{$shot->id}.png";

    Storage::disk('s3')->put($keyframePath, $imageBytes);

    $shot->update([
      'keyframe_path' => $keyframePath,
    ]);

    $shot->transitionTo(ShotState::JudgingImage);
  }
}
