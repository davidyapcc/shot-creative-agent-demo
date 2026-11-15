<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\AdvanceBriefToAudioCallback;
use App\Jobs\FailBriefOnBatchFailureCallback;
use App\Jobs\GenerateAudioJob;
use App\Jobs\RenderShotsJob;
use App\Jobs\SubmitVideoRenderJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class RenderShotsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_shots_job_batches_one_submit_per_renderable_shot_without_calling_the_provider(): void
    {
        // Arrange
        Bus::fake();

        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        $renderable = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1 prompt',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
        ]);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2 prompt',
            'state' => ShotState::Failed,
        ]);

        $videoProvider = $this->createMock(VideoProviderInterface::class);
        $videoProvider->expects($this->never())->method('submit');
        $this->app->instance(VideoProviderInterface::class, $videoProvider);

        // Act
        (new RenderShotsJob($brief->id))->handle();

        // Assert
        $this->assertSame(ShotState::JudgingImage, $renderable->refresh()->state);
        Bus::assertBatched(function (PendingBatch $batch) use ($renderable): bool {
            $submit = $batch->jobs->first();

            return $batch->jobs->count() === 1
                && $submit instanceof SubmitVideoRenderJob
                && $submit->shotId === $renderable->id
                && $submit->priorVideoAttempts === 0;
        });
    }

    public function test_render_shots_job_fails_the_brief_when_every_shot_failed_its_image_step(): void
    {
        // Arrange
        Bus::fake([GenerateAudioJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        foreach ([1, 2] as $sequence) {
            Shot::create([
                'brief_id' => $brief->id,
                'sequence' => $sequence,
                'prompt' => "Shot {$sequence} prompt",
                'state' => ShotState::Failed,
            ]);
        }

        // Act
        (new RenderShotsJob($brief->id))->handle();

        // Assert
        $this->assertSame(BriefState::Failed, $brief->refresh()->state);
        Bus::assertNothingBatched();
        Bus::assertNotDispatched(SubmitVideoRenderJob::class);
    }

    public function test_render_shots_job_leaves_a_budget_exhausted_brief_to_degraded_completion(): void
    {
        // Arrange
        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Degraded,
            'metadata' => ['budget_exhausted' => true],
        ]);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1 prompt',
            'state' => ShotState::Failed,
        ]);

        // Act
        (new RenderShotsJob($brief->id))->handle();

        // Assert
        $this->assertSame(BriefState::Degraded, $brief->refresh()->state);
    }

    public function test_advance_brief_to_audio_transitions_brief_and_dispatches_audio(): void
    {
        Queue::fake([GenerateAudioJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1',
            'state' => ShotState::Done,
            'video_path' => 'videos/shot_1.mp4',
        ]);

        $callback = new AdvanceBriefToAudioCallback($brief->id);
        $batch = $this->createMock(Batch::class);

        $callback($batch);

        $this->assertSame(BriefState::Audio, $brief->refresh()->state);
        Queue::assertPushed(GenerateAudioJob::class, fn (GenerateAudioJob $j) => $j->briefId === $brief->id);
    }

    public function test_advance_brief_to_audio_fails_the_brief_instead_of_paying_for_audio_when_no_clip_passed(): void
    {
        // Arrange
        Queue::fake([GenerateAudioJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1',
            'state' => ShotState::Failed,
            'video_path' => 'videos/shot_1.mp4',
        ]);

        // Act
        (new AdvanceBriefToAudioCallback($brief->id))($this->createMock(Batch::class));

        // Assert
        $this->assertSame(BriefState::Failed, $brief->refresh()->state);
        Queue::assertNotPushed(GenerateAudioJob::class);
    }

    public function test_advance_brief_to_audio_skips_when_brief_not_in_rendering(): void
    {
        Queue::fake([GenerateAudioJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Failed,
        ]);

        $callback = new AdvanceBriefToAudioCallback($brief->id);
        $batch = $this->createMock(Batch::class);

        $callback($batch);

        $this->assertSame(BriefState::Failed, $brief->refresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_fail_brief_on_batch_failure_callback_fails_brief_and_shots(): void
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1',
            'state' => ShotState::GeneratingVideo,
        ]);

        $callback = new FailBriefOnBatchFailureCallback($brief->id);
        $batch = $this->createMock(Batch::class);

        $callback($batch, new RuntimeException('Batch worker crashed'));

        $this->assertSame(BriefState::Failed, $brief->refresh()->state);
        $this->assertSame(ShotState::Failed, $shot->refresh()->state);
    }
}
