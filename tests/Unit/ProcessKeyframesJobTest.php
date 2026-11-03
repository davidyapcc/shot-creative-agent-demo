<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Jobs\ProcessKeyframesJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessKeyframesJobTest extends TestCase
{
  use RefreshDatabase;

  public function test_process_keyframes_generates_s3_keyframes_and_records_judgements(): void
  {
    Queue::fake([RenderShotsJob::class]);
    Storage::fake('s3');

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cinematic cyberpunk neon',
    ]);

    $shot1 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon alley with rain reflections',
      'state' => ShotState::Pending,
    ]);

    $shot2 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 2,
      'prompt' => 'Shot 2: Close-up of neon sign',
      'state' => ShotState::Pending,
    ]);

    $imageProvider = app(ImageProviderInterface::class);
    $judge = app(JudgeInterface::class);

    $job = new ProcessKeyframesJob($brief->id);
    $job->handle($imageProvider, $judge);

    $brief->refresh();
    $shot1->refresh();
    $shot2->refresh();

    // Keyframes stored to S3 and referenced on shots
    $this->assertNotNull($shot1->keyframe_path);
    $this->assertNotNull($shot2->keyframe_path);
    Storage::disk('s3')->assertExists($shot1->keyframe_path);
    Storage::disk('s3')->assertExists($shot2->keyframe_path);

    // Shot state moved through JudgingImage
    $this->assertSame(ShotState::JudgingImage, $shot1->state);
    $this->assertSame(ShotState::JudgingImage, $shot2->state);

    // Judgements recorded in rubric shape
    $this->assertDatabaseCount('judgements', 2);

    $judgement1 = Judgement::where('shot_id', $shot1->id)->first();
    $this->assertNotNull($judgement1);
    $this->assertSame('image', $judgement1->stage);
    $this->assertSame('pass', $judgement1->verdict);
    $this->assertIsArray($judgement1->scores);
    $this->assertArrayHasKey('prompt_adherence', $judgement1->scores);
    $this->assertArrayHasKey('anatomy', $judgement1->scores);
    $this->assertArrayHasKey('object_integrity', $judgement1->scores);
    $this->assertIsArray($judgement1->hard_fail_tags);

    // Brief transitioned to Rendering and dispatched RenderShotsJob
    $this->assertSame(BriefState::Rendering, $brief->state);
    Queue::assertPushed(RenderShotsJob::class, fn (RenderShotsJob $j) => $j->briefId === $brief->id);
  }

  public function test_process_keyframes_fails_brief_when_judge_verdict_fails(): void
  {
    Queue::fake([RenderShotsJob::class]);
    Storage::fake('s3');

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cinematic cyberpunk neon',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon alley',
      'state' => ShotState::Pending,
    ]);

    $imageProvider = app(ImageProviderInterface::class);

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
        reason: 'Severe anatomy distortion',
        verdict: JudgementVerdict::Fail,
      ));

    $job = new ProcessKeyframesJob($brief->id);
    $job->handle($imageProvider, $failingJudge);

    $brief->refresh();
    $shot->refresh();

    $this->assertSame(ShotState::Failed, $shot->state);
    $this->assertSame(BriefState::Failed, $brief->state);
    Queue::assertNothingPushed();
  }
}
