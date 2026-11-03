<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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

    $judge = $this->createMock(JudgeInterface::class);
    $judge->expects($this->never())->method('judgeVideo');

    $job = new PollVideoRenderJob($shot->id, 'test-render-id');
    $job->withFakeQueueInteractions();

    $startTime = microtime(true);
    $job->handle($provider, $judge);
    $elapsed = microtime(true) - $startTime;

    // Worker must not sleep (should finish in well under 1 second)
    $this->assertLessThan(0.5, $elapsed, 'Poll job blocked/slept instead of releasing immediately.');
    $this->assertTrue($job->job->isReleased(), 'Job should be released back to queue.');
    $this->assertSame(10, $job->job->releaseDelay);
    $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);
  }

  public function test_poll_job_completes_render_stores_to_s3_and_judges(): void
  {
    Storage::fake('s3');

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
        verdict: 'pass',
      ));

    $job = new PollVideoRenderJob($shot->id, 'test-render-id');
    $job->handle($provider, $judge);

    $shot->refresh();

    $this->assertSame(ShotState::Done, $shot->state);
    $this->assertNotNull($shot->video_path);
    Storage::disk('s3')->assertExists($shot->video_path);
    $this->assertSame($videoBytes, Storage::disk('s3')->get($shot->video_path));

    $judgement = Judgement::where('shot_id', $shot->id)->first();
    $this->assertNotNull($judgement);
    $this->assertSame('video', $judgement->stage);
    $this->assertSame('pass', $judgement->verdict);
    $this->assertArrayHasKey('temporal_consistency', $judgement->scores);
  }
}
