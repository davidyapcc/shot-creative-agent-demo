<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Models\Shot;
use App\Services\Budget;
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
  public const DEFAULT_PENDING_PROGRESS = 0;
  public const COMPLETED_PROGRESS = 100;

  /**
   * The number of times the job may be attempted.
   * 0 allows multiple self-releases up to retryUntil().
   */
  public int $tries = 0;

  /**
   * Maximum allowed unhandled exceptions before failing.
   */
  public int $maxExceptions = 3;

  /**
   * Delay between exception retries.
   */
  public int $backoff = self::POLL_DELAY_SECONDS;

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

    $shot = Shot::with('brief')->find($this->shotId);

    if ($shot === null || !app(Budget::class)->chargeShotStep($shot, 'video', 'poll'))
    {
      return;
    }

    $result = $videoProvider->poll($this->renderId);

    if ($result->isPending())
    {
      $progress = $result->progress ?? self::DEFAULT_PENDING_PROGRESS;
      $pendingShot = Shot::find($this->shotId);
      if ($pendingShot !== null)
      {
        $this->storeVideoProgress($pendingShot, $progress);
      }
      VideoProgressUpdated::dispatch($this->briefId, $this->shotId, $progress);

      $this->release(self::POLL_DELAY_SECONDS);

      return;
    }

    if ($result->isFailed())
    {
      $this->batch()?->cancel();
      $this->fail(new RuntimeException('Video render failed: ' . ($result->error ?? 'unknown error')));

      return;
    }

    $shot = Shot::with('brief')->findOrFail($this->shotId);

    $videoPath = "videos/brief_{$shot->brief_id}_shot_{$shot->id}.mp4";

    Storage::disk('s3')->put($videoPath, (string) $result->videoContent);

    $this->storeVideoProgress($shot, self::COMPLETED_PROGRESS, [
      'video_path' => $videoPath,
    ]);

    if (!$shot->advanceUnlessStopped(ShotState::JudgingVideo))
    {
      return;
    }

    VideoProgressUpdated::dispatch($this->briefId, $this->shotId, self::COMPLETED_PROGRESS);
    ShotUpdated::dispatch($shot);

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

  /**
   * @param array<string, mixed> $attributes
   */
  private function storeVideoProgress(Shot $shot, int $progress, array $attributes = []): void
  {
    $shot->update([
      ...$attributes,
      'metadata' => array_merge($shot->metadata ?? [], [
        'video_progress' => $progress,
      ]),
    ]);
  }
}
