<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DummyWorkerFollowUpJob implements ShouldQueue
{
  use Queueable;

  public static bool $executed = false;

  public function handle(): void
  {
    self::$executed = true;
  }
}

class PollVideoRenderJobTest extends TestCase
{
  use RefreshDatabase;

  public function test_poll_job_releases_itself_without_blocking_worker_when_pending(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::GeneratingVideo,
    ]);

    $provider = $this->createMock(VideoProviderInterface::class);
    $provider->expects($this->once())
      ->method('poll')
      ->with('test-render-id')
      ->willReturn(VideoRenderResult::pending());

    $job = new PollVideoRenderJob($shot->id, 'test-render-id', $brief->id);
    $job->withFakeQueueInteractions();

    $job->handle($provider);

    $this->assertTrue($job->job->isReleased(), 'Job should be released back to queue.');
    $this->assertSame(PollVideoRenderJob::POLL_DELAY_SECONDS, $job->job->releaseDelay);
    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);
  }

  public function test_pending_render_does_not_block_worker_from_processing_subsequent_jobs(): void
  {
    config(['queue.default' => 'database']);
    DummyWorkerFollowUpJob::$executed = false;

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::GeneratingVideo,
    ]);

    $mockProvider = $this->createMock(VideoProviderInterface::class);
    $mockProvider->expects($this->exactly(2))
      ->method('poll')
      ->willReturnOnConsecutiveCalls(
        VideoRenderResult::pending(),
        VideoRenderResult::completed('TEST_MP4_BYTES'),
      );
    $this->app->instance(VideoProviderInterface::class, $mockProvider);

    // Queue the poll job first, followed immediately by another job
    Queue::push(new PollVideoRenderJob($shot->id, 'render-pending-test', $brief->id));
    Queue::push(new DummyWorkerFollowUpJob());

    // Worker turn 1: processes the poll job, which releases itself with a 10s delay
    Artisan::call('queue:work', ['--once' => true]);

    $this->assertFalse(DummyWorkerFollowUpJob::$executed);

    // Worker turn 2: worker is NOT blocked; it immediately picks up and runs the follow-up job!
    Artisan::call('queue:work', ['--once' => true]);

    $this->assertTrue(DummyWorkerFollowUpJob::$executed, 'Worker should process next job instead of waiting.');

    // Fast-forward 11 seconds: released job is now available again
    $this->travel(11)->seconds();

    // Worker turn 3: picks up the released poll job on attempt 2 without MaxAttemptsExceeded
    Artisan::call('queue:work', ['--once' => true]);

    $this->assertSame(ShotState::JudgingVideo, $shot->refresh()->state);
  }

  public function test_poll_job_completes_render_stores_to_s3_and_dispatches_judge_job(): void
  {
    Storage::fake('s3');
    Queue::fake([JudgeVideoJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
      'style_anchor' => 'cyberpunk style',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::GeneratingVideo,
    ]);

    $videoBytes = 'FAKE_MP4_BYTES';
    $provider = $this->createMock(VideoProviderInterface::class);
    $provider->expects($this->once())
      ->method('poll')
      ->with('test-render-id')
      ->willReturn(VideoRenderResult::completed($videoBytes));

    $job = new PollVideoRenderJob($shot->id, 'test-render-id', $brief->id);
    $job->handle($provider);

    $shot->refresh();

    $this->assertSame(ShotState::JudgingVideo, $shot->state);
    $this->assertNotNull($shot->video_path);
    Storage::disk('s3')->assertExists($shot->video_path);
    $this->assertSame($videoBytes, Storage::disk('s3')->get($shot->video_path));

    Queue::assertPushed(JudgeVideoJob::class, fn (JudgeVideoJob $j) => $j->shotId === $shot->id);
  }

  public function test_judge_video_job_persists_judgement_and_transitions_shot_to_done(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
      'style_anchor' => 'cyberpunk style',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::JudgingVideo,
      'video_path' => 'videos/test.mp4',
    ]);

    $judge = $this->createMock(JudgeInterface::class);
    $judge->expects($this->once())
      ->method('judgeVideo')
      ->willReturn(new JudgementResult(
        scores: [
          'prompt_adherence' => 5,
          'anatomy' => 5,
          'object_integrity' => 5,
          'temporal_consistency' => 5,
        ],
        hardFailTags: [],
        reason: 'Looks great',
        verdict: JudgementVerdict::Pass,
      ));

    $job = new JudgeVideoJob($shot->id, $brief->id);
    $job->handle($judge);

    $this->assertSame(ShotState::Done, $shot->refresh()->state);

    $judgement = Judgement::where('shot_id', $shot->id)->first();
    $this->assertNotNull($judgement);
    $this->assertSame('video', $judgement->stage);
    $this->assertSame('pass', $judgement->verdict);
    $this->assertArrayHasKey('temporal_consistency', $judgement->scores);
  }
}
