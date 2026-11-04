<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Jobs\AdvanceBriefToRenderingCallback;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\ProcessKeyframesJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessKeyframesJobTest extends TestCase
{
  use RefreshDatabase;

  public function test_process_keyframes_dispatches_batched_chains_for_shots(): void
  {
    Bus::fake([RenderShotsJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cinematic cyberpunk neon',
    ]);

    Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon alley',
      'state' => ShotState::Pending,
    ]);

    Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 2,
      'prompt' => 'Shot 2: Neon sign',
      'state' => ShotState::Pending,
    ]);

    $job = new ProcessKeyframesJob($brief->id);
    $job->handle();

    Bus::assertBatched(function (PendingBatch $batch)
    {
      $this->assertCount(2, $batch->jobs);

      return true;
    });
  }

  public function test_generate_keyframe_job_stores_image_to_s3_and_transitions_shot(): void
  {
    Storage::fake('s3');

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cyberpunk style',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::Pending,
    ]);

    $imageProvider = app(ImageProviderInterface::class);

    $job = new GenerateKeyframeJob($shot->id, $brief->id);
    $this->assertSame('generate', $job->queue);

    $job->handle($imageProvider);

    $shot->refresh();
    $this->assertSame(ShotState::JudgingImage, $shot->state);
    $this->assertNotNull($shot->keyframe_path);
    Storage::disk('s3')->assertExists($shot->keyframe_path);
  }

  public function test_judge_keyframe_job_persists_judgement_on_judge_queue(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cyberpunk style',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::JudgingImage,
      'keyframe_path' => 'keyframes/test.png',
    ]);

    $judge = app(JudgeInterface::class);

    $job = new JudgeKeyframeJob($shot->id, $brief->id);
    $this->assertSame('judge', $job->queue);

    $job->handle($judge, app(AgentInterface::class));

    $judgement = Judgement::where('shot_id', $shot->id)->first();
    $this->assertNotNull($judgement);
    $this->assertSame('image', $judgement->stage);
    $this->assertSame('pass', $judgement->verdict);
  }

  public function test_judge_keyframe_job_does_not_fail_the_brief_on_the_first_verdict_fail(): void
  {
    Bus::fake();

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cyberpunk style',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::JudgingImage,
      'keyframe_path' => 'keyframes/test.png',
    ]);

    $failingJudge = $this->createMock(JudgeInterface::class);
    $failingJudge->expects($this->once())
      ->method('judgeImage')
      ->willReturn(new JudgementResult(
        scores: [
          'prompt_adherence' => 2,
          'anatomy' => 1,
          'object_integrity' => 2,
        ],
        hardFailTags: ['anatomy'],
        reason: 'Severe distortion',
        verdict: JudgementVerdict::Fail,
      ));

    $job = new JudgeKeyframeJob($shot->id, $brief->id);
    $job->handle($failingJudge, app(AgentInterface::class));

    $this->assertSame(ShotState::JudgingImage, $shot->refresh()->state);
    $this->assertSame(BriefState::Keyframes, $brief->refresh()->state);
  }

  public function test_advance_brief_to_rendering_callback_transitions_and_dispatches(): void
  {
    Queue::fake([RenderShotsJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Keyframes,
    ]);

    $callback = new AdvanceBriefToRenderingCallback($brief->id);
    $batch = $this->createMock(Batch::class);
    $batch->expects($this->once())->method('cancelled')->willReturn(false);

    $callback($batch);

    $this->assertSame(BriefState::Rendering, $brief->refresh()->state);
    Queue::assertPushed(RenderShotsJob::class, fn (RenderShotsJob $j) => $j->briefId === $brief->id);
  }

  public function test_advance_brief_to_rendering_callback_ignores_cancelled_batch(): void
  {
    Queue::fake([RenderShotsJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Keyframes,
    ]);

    $callback = new AdvanceBriefToRenderingCallback($brief->id);
    $batch = $this->createMock(Batch::class);
    $batch->expects($this->once())->method('cancelled')->willReturn(true);

    $callback($batch);

    $this->assertSame(BriefState::Keyframes, $brief->refresh()->state);
    Queue::assertNothingPushed();
  }
}
