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
use App\Jobs\AdvanceBriefToAudioCallback;
use App\Jobs\GenerateAudioJob;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\ShotStepRetry;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    $job->handle($provider, app(Budget::class));

    $this->assertTrue($job->job->isReleased(), 'Job should be released back to queue.');
    $this->assertSame(PollVideoRenderJob::POLL_DELAY_SECONDS, $job->job->releaseDelay);
    $shot->refresh();
    $this->assertSame(ShotState::GeneratingVideo, $shot->state);
    $this->assertSame(PollVideoRenderJob::DEFAULT_PENDING_PROGRESS, $shot->metadata['video_progress']);
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

    Queue::push(new PollVideoRenderJob($shot->id, 'render-pending-test', $brief->id));
    Queue::push(new DummyWorkerFollowUpJob());

    // Worker turn 1: poll job releases itself with 10s delay
    Artisan::call('queue:work', ['--once' => true]);
    $this->assertFalse(DummyWorkerFollowUpJob::$executed);

    // Worker turn 2: worker runs follow-up job immediately
    Artisan::call('queue:work', ['--once' => true]);
    $this->assertTrue(DummyWorkerFollowUpJob::$executed);

    // Fast-forward past delay
    $this->travel(11)->seconds();

    // Worker turn 3: poll job resumes on attempt 2 without MaxAttemptsExceeded
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
      'metadata' => ['render_id' => 'test-render-id'],
    ]);

    $videoBytes = 'FAKE_MP4_BYTES';
    $provider = $this->createMock(VideoProviderInterface::class);
    $provider->expects($this->once())
      ->method('poll')
      ->with('test-render-id')
      ->willReturn(VideoRenderResult::completed($videoBytes));

    $job = new PollVideoRenderJob($shot->id, 'test-render-id', $brief->id);
    $job->handle($provider, app(Budget::class));

    $shot->refresh();

    $this->assertSame(ShotState::JudgingVideo, $shot->state);
    $this->assertSame(PollVideoRenderJob::COMPLETED_PROGRESS, $shot->metadata['video_progress']);
    $this->assertSame('test-render-id', $shot->metadata['render_id']);
    $this->assertNotNull($shot->video_path);
    Storage::disk('s3')->assertExists($shot->video_path);
    $this->assertSame($videoBytes, Storage::disk('s3')->get($shot->video_path));

    Queue::assertPushed(JudgeVideoJob::class, fn (JudgeVideoJob $j) => $j->shotId === $shot->id);
  }

  public function test_poll_job_fails_immediately_when_provider_reports_failure(): void
  {
    config(['queue.default' => 'database']);

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
      ->with('failed-render-id')
      ->willReturn(VideoRenderResult::failed('CUDA out of memory'));
    $this->app->instance(VideoProviderInterface::class, $provider);

    Queue::push(new PollVideoRenderJob($shot->id, 'failed-render-id', $brief->id));

    Artisan::call('queue:work', ['--once' => true]);

    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Failed, $brief->refresh()->state);
  }

  public function test_poll_job_fails_brief_when_retry_until_deadline_exceeded(): void
  {
    config(['queue.default' => 'database']);

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
      ->with('timeout-render-id')
      ->willReturn(VideoRenderResult::pending());
    $this->app->instance(VideoProviderInterface::class, $provider);

    $job = new PollVideoRenderJob($shot->id, 'timeout-render-id', $brief->id);
    $this->assertSame(10, (int) now()->diffInMinutes($job->retryUntil()));

    Queue::push($job);

    // Initial attempt releases with 10s delay
    Artisan::call('queue:work', ['--once' => true]);
    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);

    // Fast-forward past the 10-minute retryUntil deadline
    $this->travel(11)->minutes();

    // Next worker pass: deadline exceeded, worker marks job failed and runs failed() callback
    Artisan::call('queue:work', ['--once' => true]);

    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Failed, $brief->refresh()->state);
  }

  public function test_poll_job_retries_unexpected_exceptions_up_to_max_exceptions_and_fails_brief(): void
  {
    config(['queue.default' => 'database']);

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
    $provider->expects($this->exactly(3))
      ->method('poll')
      ->willThrowException(new RuntimeException('Transient provider outage'));
    $this->app->instance(VideoProviderInterface::class, $provider);

    Queue::push(new PollVideoRenderJob($shot->id, 'retry-exception-id', $brief->id));

    // Attempt 1: failure caught, job released with backoff
    Artisan::call('queue:work', ['--once' => true]);
    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);

    // Attempt 2: failure caught, job released with backoff
    $this->travel(11)->seconds();
    Artisan::call('queue:work', ['--once' => true]);
    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);

    // Attempt 3: 3rd exception reaches maxExceptions = 3 and fails permanently
    $this->travel(11)->seconds();
    Artisan::call('queue:work', ['--once' => true]);

    $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    $this->assertSame(BriefState::Failed, $brief->refresh()->state);
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
    $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));

    $this->assertSame(ShotState::Done, $shot->refresh()->state);

    $judgement = Judgement::where('shot_id', $shot->id)->first();
    $this->assertNotNull($judgement);
    $this->assertSame('video', $judgement->stage);
    $this->assertSame('pass', $judgement->verdict);
    $this->assertArrayHasKey('temporal_consistency', $judgement->scores);
  }

  public function test_judge_video_job_does_not_fail_the_brief_on_the_first_verdict_fail(): void
  {
    Bus::fake();

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

    $failingJudge = $this->createMock(JudgeInterface::class);
    $failingJudge->expects($this->once())
      ->method('judgeVideo')
      ->willReturn(new JudgementResult(
        scores: [
          'prompt_adherence' => 2,
          'anatomy' => 2,
          'object_integrity' => 2,
          'temporal_consistency' => 1,
        ],
        hardFailTags: ['temporal_flicker'],
        reason: 'Extreme temporal flicker',
        verdict: JudgementVerdict::Fail,
      ));

    $job = new JudgeVideoJob($shot->id, $brief->id);
    $job->handle($failingJudge, app(ShotStepRetry::class), app(Budget::class));

    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);
    $this->assertSame(BriefState::Rendering, $brief->refresh()->state);
  }

  public function test_advance_brief_to_audio_callback_ignores_cancelled_batch(): void
  {
    Queue::fake([GenerateAudioJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
    ]);

    $callback = new AdvanceBriefToAudioCallback($brief->id);
    $batch = $this->createMock(Batch::class);
    $batch->expects($this->once())->method('cancelled')->willReturn(true);

    $callback($batch);

    $this->assertSame(BriefState::Rendering, $brief->refresh()->state);
    Queue::assertNothingPushed();
  }
}
