<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Events\BriefStateChanged;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PlanBriefJob;
use App\Jobs\PollVideoRenderJob;
use App\Jobs\ProcessKeyframesJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PipelineBroadcastsTest extends TestCase
{
  use RefreshDatabase;

  public function test_plan_brief_dispatches_shot_updated_for_created_shots(): void
  {
    Queue::fake([ProcessKeyframesJob::class]);
    Event::fake([ShotUpdated::class, BriefStateChanged::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Pending,
    ]);

    $agent = app(AgentInterface::class);
    (new PlanBriefJob($brief->id))->handle($agent);

    Event::assertDispatched(ShotUpdated::class, 3);
  }

  public function test_generate_keyframe_dispatches_shot_updated_with_keyframe(): void
  {
    Storage::fake('s3');
    Event::fake([ShotUpdated::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cinematic, neon rain',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Neon alley reflection',
      'state' => ShotState::Pending,
    ]);

    $imageProvider = app(ImageProviderInterface::class);
    (new GenerateKeyframeJob($shot->id, $brief->id))->handle($imageProvider);

    Event::assertDispatched(ShotUpdated::class, function (ShotUpdated $event) use ($shot): bool
    {
      return $event->shot->id === $shot->id
        && $event->shot->keyframe_path !== null
        && $event->shot->state === ShotState::JudgingImage;
    });
  }

  public function test_judge_keyframe_dispatches_judge_verdict_recorded_with_failure_tag(): void
  {
    Event::fake([JudgeVerdictRecorded::class, ShotUpdated::class, BriefStateChanged::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Keyframes,
      'style_anchor' => 'cinematic, neon rain',
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Neon alley reflection',
      'state' => ShotState::JudgingImage,
      'keyframe_path' => 'keyframes/test.png',
    ]);

    $judge = $this->createMock(JudgeInterface::class);
    $judge->expects($this->once())
      ->method('judgeImage')
      ->willReturn(new JudgementResult(
        scores: ['prompt_adherence' => 2, 'anatomy' => 1],
        hardFailTags: ['anatomy'],
        reason: 'Hand distorted',
        verdict: JudgementVerdict::Fail,
      ));

    (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge);

    Event::assertDispatched(
      JudgeVerdictRecorded::class,
      function (JudgeVerdictRecorded $event) use ($brief, $shot): bool
      {
        $stage = $event->stage instanceof JudgementStage ? $event->stage->value : $event->stage;
        $verdict = $event->verdict instanceof JudgementVerdict ? $event->verdict->value : $event->verdict;

        return $event->briefId === $brief->id
          && $event->shotId === $shot->id
          && $stage === 'image'
          && $verdict === 'fail'
          && $event->tag === 'anatomy'
          && $event->hardFailTags === ['anatomy'];
      }
    );
    Event::assertDispatched(ShotUpdated::class);
  }

  public function test_poll_video_render_dispatches_video_progress_when_pending(): void
  {
    Event::fake([VideoProgressUpdated::class, ShotUpdated::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Rendering,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Neon alley reflection',
      'state' => ShotState::GeneratingVideo,
    ]);

    $videoProvider = $this->createMock(VideoProviderInterface::class);
    $videoProvider->expects($this->once())
      ->method('poll')
      ->willReturn(VideoRenderResult::pending(45));

    (new PollVideoRenderJob($shot->id, 'render_123', $brief->id))->handle($videoProvider);

    Event::assertDispatched(
      VideoProgressUpdated::class,
      function (VideoProgressUpdated $event) use ($brief, $shot): bool
      {
        return $event->briefId === $brief->id
          && $event->shotId === $shot->id
          && $event->progress === 45;
      }
    );
  }

  public function test_judge_video_dispatches_judge_verdict_recorded_with_failure_tag(): void
  {
    Event::fake([JudgeVerdictRecorded::class, ShotUpdated::class, BriefStateChanged::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Rendering,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Neon alley reflection',
      'state' => ShotState::JudgingVideo,
      'video_path' => 'videos/test.mp4',
    ]);

    $judge = $this->createMock(JudgeInterface::class);
    $judge->expects($this->once())
      ->method('judgeVideo')
      ->willReturn(new JudgementResult(
        scores: ['temporal_consistency' => 1],
        hardFailTags: ['temporal_flicker'],
        reason: 'Severe flicker across frames',
        verdict: JudgementVerdict::Fail,
      ));

    (new JudgeVideoJob($shot->id, $brief->id))->handle($judge);

    Event::assertDispatched(
      JudgeVerdictRecorded::class,
      function (JudgeVerdictRecorded $event) use ($brief, $shot): bool
      {
        $stage = $event->stage instanceof JudgementStage ? $event->stage->value : $event->stage;
        $verdict = $event->verdict instanceof JudgementVerdict ? $event->verdict->value : $event->verdict;

        return $event->briefId === $brief->id
          && $event->shotId === $shot->id
          && $stage === 'video'
          && $verdict === 'fail'
          && $event->tag === 'temporal_flicker';
      }
    );
    Event::assertDispatched(ShotUpdated::class);
  }
}
