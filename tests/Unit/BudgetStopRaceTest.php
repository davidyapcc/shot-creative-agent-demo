<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A job already mid-call when the budget cap is hit finds its shot failed by
 * DegradedCompletion. It must drop its result quietly: no exception (which
 * would flip the muxing brief to failed) and no overwrite of the failed shot.
 */
class BudgetStopRaceTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    Storage::fake('s3');
    Bus::fake();
  }

  public function test_late_keyframe_does_not_revive_a_shot_the_budget_stop_failed(): void
  {
    // Arrange
    [$brief, $shot] = $this->briefWithShot(BriefState::Keyframes, ShotState::Pending);

    $images = $this->createMock(ImageProviderInterface::class);
    $images->method('generateImage')->willReturnCallback(function () use ($shot, $brief): string
    {
      $this->stopForBudget($brief, $shot);

      return 'png-bytes';
    });

    // Act
    (new GenerateKeyframeJob($shot->id, $brief->id))->handle($images);

    // Assert
    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
  }

  public function test_late_render_does_not_throw_or_queue_a_judge_after_the_budget_stop(): void
  {
    // Arrange
    [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::GeneratingVideo);

    $videos = $this->createMock(VideoProviderInterface::class);
    $videos->method('poll')->willReturnCallback(function () use ($shot, $brief): VideoRenderResult
    {
      $this->stopForBudget($brief, $shot);

      return VideoRenderResult::completed('mp4-bytes');
    });

    // Act
    (new PollVideoRenderJob($shot->id, 'render-1', $brief->id))->handle($videos);

    // Assert
    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
    Bus::assertNotDispatched(JudgeVideoJob::class);
  }

  public function test_late_passing_video_verdict_does_not_revive_a_shot_the_budget_stop_failed(): void
  {
    // Arrange
    [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::JudgingVideo);

    $judge = $this->createMock(JudgeInterface::class);
    $judge->method('judgeVideo')->willReturnCallback(function () use ($shot, $brief): JudgementResult
    {
      $this->stopForBudget($brief, $shot);

      return new JudgementResult(['prompt_adherence' => 5, 'temporal_consistency' => 5]);
    });

    // Act
    (new JudgeVideoJob($shot->id, $brief->id))->handle($judge, $this->createMock(AgentInterface::class));

    // Assert
    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
  }

  /**
   * @return array{0: Brief, 1: Shot}
   */
  private function briefWithShot(BriefState $briefState, ShotState $shotState): array
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => $briefState,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => $shotState,
      'keyframe_path' => 'keyframes/shot_1.png',
      'video_path' => 'videos/shot_1.mp4',
    ]);

    return [$brief, $shot];
  }

  /**
   * What DegradedCompletion leaves behind while the provider call is in flight.
   */
  private function stopForBudget(Brief $brief, Shot $shot): void
  {
    Brief::query()->whereKey($brief->id)->update([
      'state' => BriefState::Muxing->value,
      'metadata' => json_encode(['budget_exhausted' => true]),
    ]);
    Shot::query()->whereKey($shot->id)->update([
      'state' => ShotState::Failed->value,
    ]);
  }
}
