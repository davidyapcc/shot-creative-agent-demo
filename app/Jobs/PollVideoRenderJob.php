<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Models\Shot;
use App\Traits\FailsBriefOnJobFailure;
use DateTimeInterface;
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

  public const POLL_DELAY_SECONDS = 10;
  public const MAX_POLL_MINUTES = 10;

  /**
   * The number of times the job may be attempted.
   * 0 allows multiple self-releases up to retryUntil().
   */
  public int $tries = 0;

  public function __construct(
    public readonly int $shotId,
    public readonly string $renderId,
    public readonly int $briefId,
  )
  {
    $this->onQueue('poll');
  }

  /**
   * Determine the time at which the job should timeout.
   */
  public function retryUntil(): DateTimeInterface
  {
    return now()->addMinutes(self::MAX_POLL_MINUTES);
  }

  public function handle(VideoProviderInterface $videoProvider): void
  {
    if ($this->batch()?->cancelled())
    {
      return;
    }

    $result = $videoProvider->poll($this->renderId);

    if ($result->isPending())
    {
      $this->release(self::POLL_DELAY_SECONDS);

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

    $judgeJob = new JudgeVideoJob($shot->id, $shot->brief_id);

    if ($this->batch() !== null)
    {
      $this->batch()->add([$judgeJob]);
    }
    else
    {
      dispatch($judgeJob);
    }
  }
}
