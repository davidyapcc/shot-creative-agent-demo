<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PollVideoRenderJob;
use App\Jobs\RewritePromptJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\PaidCall;
use App\Services\ShotStepRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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
        $images->method('generateImage')->willReturnCallback(function () use ($shot, $brief): string {
            $this->stopForBudget($brief, $shot);

            return 'png-bytes';
        });

        // Act
        (new GenerateKeyframeJob($shot->id, $brief->id))->handle($images, app(PaidCall::class));

        // Assert
        $this->assertSame(ShotState::Failed, $shot->refresh()->state);
        $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
    }

    public function test_late_keyframe_leaves_no_path_and_counts_no_attempt(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot(BriefState::Keyframes, ShotState::Pending);
        $shot->update(['keyframe_path' => null]);

        $images = $this->createMock(ImageProviderInterface::class);
        $images->method('generateImage')->willReturnCallback(function () use ($shot, $brief): string {
            $this->stopForBudget($brief, $shot);

            return 'png-bytes';
        });

        // Act
        (new GenerateKeyframeJob($shot->id, $brief->id))->handle($images, app(PaidCall::class));

        // Assert
        $shot->refresh();
        $this->assertNull($shot->keyframe_path);
        $this->assertSame(0, $shot->attempts);
    }

    public function test_late_render_does_not_throw_or_queue_a_judge_after_the_budget_stop(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::GeneratingVideo);

        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('poll')->willReturnCallback(function () use ($shot, $brief): VideoRenderResult {
            $this->stopForBudget($brief, $shot);

            return VideoRenderResult::completed('mp4-bytes');
        });

        // Act
        (new PollVideoRenderJob($shot->id, 'render-1', $brief->id))->handle($videos, app(Budget::class));

        // Assert
        $this->assertSame(ShotState::Failed, $shot->refresh()->state);
        $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
        Bus::assertNotDispatched(JudgeVideoJob::class);
    }

    public function test_late_render_stores_no_clip_path_and_broadcasts_nothing_after_the_budget_stop(): void
    {
        // Arrange
        Event::fake([VideoProgressUpdated::class, ShotUpdated::class]);
        [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::GeneratingVideo);
        $pathBeforePoll = $shot->video_path;
        $lateClipPath = "videos/brief_{$brief->id}_shot_{$shot->id}.mp4";

        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('poll')->willReturnCallback(function () use ($shot, $brief): VideoRenderResult {
            $this->stopForBudget($brief, $shot);

            return VideoRenderResult::completed('mp4-bytes');
        });

        // Act
        (new PollVideoRenderJob($shot->id, 'render-1', $brief->id))->handle($videos, app(Budget::class));

        // Assert
        $shot->refresh();
        $this->assertSame($pathBeforePoll, $shot->video_path);
        $this->assertArrayNotHasKey('video_progress', $shot->metadata ?? []);
        Storage::disk('s3')->assertMissing($lateClipPath);
        Event::assertNotDispatched(VideoProgressUpdated::class);
        Event::assertNotDispatched(ShotUpdated::class);
    }

    public function test_late_passing_video_verdict_does_not_revive_a_shot_the_budget_stop_failed(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::JudgingVideo);

        $judge = $this->createMock(JudgeInterface::class);
        $judge->method('judgeVideo')->willReturnCallback(function () use ($shot, $brief): JudgementResult {
            $this->stopForBudget($brief, $shot);

            return new JudgementResult(['prompt_adherence' => 5, 'temporal_consistency' => 5]);
        });

        // Act
        (new JudgeVideoJob($shot->id, $brief->id))->handle($judge, app(ShotStepRetry::class), app(PaidCall::class));

        // Assert
        $this->assertSame(ShotState::Failed, $shot->refresh()->state);
        $this->assertSame(BriefState::Muxing, $brief->refresh()->state);
    }

    public function test_late_keyframe_verdict_records_no_judgement_and_broadcasts_nothing(): void
    {
        // Arrange
        Event::fake([JudgeVerdictRecorded::class, ShotUpdated::class]);
        [$brief, $shot] = $this->briefWithShot(BriefState::Keyframes, ShotState::JudgingImage);

        $judge = $this->createMock(JudgeInterface::class);
        $judge->method('judgeImage')->willReturnCallback(function () use ($shot, $brief): JudgementResult {
            $this->stopForBudget($brief, $shot);

            return new JudgementResult(['prompt_adherence' => 5]);
        });

        // Act
        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, app(ShotStepRetry::class), app(PaidCall::class));

        // Assert
        $this->assertSame(0, Judgement::query()->count());
        Event::assertNotDispatched(JudgeVerdictRecorded::class);
        Event::assertNotDispatched(ShotUpdated::class);
    }

    public function test_late_failing_video_verdict_records_nothing_and_queues_no_rewrite(): void
    {
        // Arrange
        Event::fake([JudgeVerdictRecorded::class, ShotUpdated::class]);
        [$brief, $shot] = $this->briefWithShot(BriefState::Rendering, ShotState::JudgingVideo);

        $judge = $this->createMock(JudgeInterface::class);
        $judge->method('judgeVideo')->willReturnCallback(function () use ($shot, $brief): JudgementResult {
            $this->stopForBudget($brief, $shot);

            return new JudgementResult(
                scores: ['prompt_adherence' => 1],
                reason: 'Wrong subject',
                verdict: JudgementVerdict::Fail,
            );
        });

        // Act
        (new JudgeVideoJob($shot->id, $brief->id))->handle($judge, app(ShotStepRetry::class), app(PaidCall::class));

        // Assert
        $this->assertSame(0, Judgement::query()->count());
        Event::assertNotDispatched(JudgeVerdictRecorded::class);
        Event::assertNotDispatched(ShotUpdated::class);
        Bus::assertNotDispatched(RewritePromptJob::class);
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
