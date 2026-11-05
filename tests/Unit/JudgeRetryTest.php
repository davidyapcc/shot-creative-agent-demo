<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\ShotStepRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JudgeRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_fail_rewrites_and_regenerates_only_that_shot(): void
    {
        Bus::fake();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
        ]);

        $other = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: neon sign',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_2.png',
        ]);

        $agent = new RecordingAgent;
        $judge = $this->failingImageJudge('Left hand has six fingers.', ['anatomy' => 2, 'prompt_adherence' => 4, 'object_integrity' => 5]);

        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));

        $this->assertSame(BriefState::Keyframes, $brief->refresh()->state);
        $this->assertSame(ShotState::JudgingImage, $other->refresh()->state);
        $this->assertNotSame(ShotState::Failed, $shot->refresh()->state);

        $this->assertCount(1, $agent->contexts);
        $this->assertSame('15s product teaser, rainy Tokyo, neon', $agent->contexts[0]['brief']);
        $this->assertSame('Shot 1: neon alley', $agent->contexts[0]['original_prompt']);
        $this->assertSame('Left hand has six fingers.', $agent->contexts[0]['reason']);
        $this->assertSame(
            ['anatomy' => 2, 'prompt_adherence' => 4, 'object_integrity' => 5],
            $agent->contexts[0]['failed_scores'],
        );
        $this->assertSame([], $agent->contexts[0]['previous_attempts']);

        $this->assertStringStartsWith('Shot 1: neon alley', $shot->prompt);

        Bus::assertChained([
            function (GenerateKeyframeJob $job) use ($shot): bool {
                return $job->shotId === $shot->id && $job->briefId === $shot->brief_id;
            },
            function (JudgeKeyframeJob $job) use ($shot): bool {
                return $job->shotId === $shot->id && $job->briefId === $shot->brief_id;
            },
        ]);
        Bus::assertNotDispatched(GenerateKeyframeJob::class, function (GenerateKeyframeJob $job) use ($other): bool {
            return $job->shotId === $other->id;
        });
    }

    public function test_second_image_fail_passes_each_earlier_prompt_and_reason(): void
    {
        Bus::fake();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
            'metadata' => [
                'original_prompt' => 'Shot 1: neon alley',
                'step_attempts' => ['image' => 2],
                'prompt_attempts' => [
                    'image' => [
                        ['prompt' => 'Shot 1: neon alley', 'reason' => 'Left hand has six fingers.'],
                    ],
                ],
            ],
        ]);

        $shot->update([
            'prompt' => 'Shot 1: neon alley [rewrite]',
        ]);

        $agent = new RecordingAgent;
        $judge = $this->failingImageJudge('Still six fingers.', ['anatomy' => 1, 'prompt_adherence' => 4, 'object_integrity' => 5]);

        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));

        $this->assertSame([
            ['prompt' => 'Shot 1: neon alley', 'reason' => 'Left hand has six fingers.'],
        ], $agent->contexts[0]['previous_attempts']);
        $this->assertSame('Shot 1: neon alley', $agent->contexts[0]['original_prompt']);
        $this->assertSame('Still six fingers.', $agent->contexts[0]['reason']);
    }

    public function test_every_rewrite_receives_the_planned_prompt_as_original_prompt(): void
    {
        // Arrange
        Bus::fake();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
        ]);

        $agent = new RecordingAgent;
        $judge = $this->failingImageJudge('Left hand has six fingers.', ['anatomy' => 2, 'prompt_adherence' => 4, 'object_integrity' => 5], calls: 2);

        // Act
        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));
        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));

        // Assert
        $this->assertCount(2, $agent->contexts);
        $this->assertSame('Shot 1: neon alley', $agent->contexts[0]['original_prompt']);
        $this->assertSame('Shot 1: neon alley', $agent->contexts[1]['original_prompt']);
        $this->assertSame('Shot 1: neon alley [rewrite]', $shot->refresh()->prompt);
        $this->assertSame('Shot 1: neon alley', $shot->metadata['original_prompt']);
    }

    public function test_image_step_stops_after_two_retries_without_failing_the_brief(): void
    {
        Bus::fake();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
            'attempts' => 3,
            'metadata' => [
                'step_attempts' => ['image' => 3],
            ],
        ]);

        $agent = new RecordingAgent;
        $judge = $this->failingImageJudge('Still six fingers.', ['anatomy' => 1]);

        (new JudgeKeyframeJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));

        $this->assertSame([], $agent->contexts);
        $this->assertSame(ShotState::Failed, $shot->refresh()->state);
        $this->assertSame(3, $shot->attempts);
        $this->assertSame(BriefState::Keyframes, $brief->refresh()->state);
        Bus::assertNotDispatched(GenerateKeyframeJob::class);
    }

    public function test_keyframe_generation_records_the_image_attempt_count(): void
    {
        Storage::fake('s3');

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Keyframes,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::Pending,
        ]);

        $job = new GenerateKeyframeJob($shot->id, $brief->id);
        $job->handle(app(ImageProviderInterface::class), app(Budget::class));

        $shot->refresh();
        $this->assertSame(1, $shot->attempts);
        $this->assertSame(1, $shot->metadata['step_attempts']['image']);
        $this->assertSame(ShotState::JudgingImage, $shot->state);

        $job->handle(app(ImageProviderInterface::class), app(Budget::class));

        $shot->refresh();
        $this->assertSame(2, $shot->attempts);
        $this->assertSame(2, $shot->metadata['step_attempts']['image']);
    }

    public function test_video_fail_rewrites_and_resubmits_only_that_shot(): void
    {
        Bus::fake([PollVideoRenderJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Rendering,
            'style_anchor' => 'cinematic neon rain',
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingVideo,
            'keyframe_path' => 'keyframes/shot_1.png',
            'video_path' => 'videos/shot_1.mp4',
            'attempts' => 2,
            'metadata' => [
                'step_attempts' => ['image' => 2, 'video' => 1],
                'prompt_attempts' => [
                    'video' => [
                        ['prompt' => 'Shot 1: original', 'reason' => 'Extreme temporal flicker'],
                    ],
                ],
            ],
        ]);

        $other = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: neon sign',
            'state' => ShotState::Done,
            'video_path' => 'videos/shot_2.mp4',
        ]);

        $video = $this->createMock(VideoProviderInterface::class);
        $video->expects($this->once())
            ->method('submit')
            ->with('Shot 1: neon alley [rewrite]', 'keyframes/shot_1.png')
            ->willReturn('render-2');
        $this->app->instance(VideoProviderInterface::class, $video);

        $agent = new RecordingAgent;
        $judge = $this->createMock(JudgeInterface::class);
        $judge->expects($this->once())
            ->method('judgeVideo')
            ->willReturn(new JudgementResult(
                scores: ['temporal_consistency' => 1, 'prompt_adherence' => 4, 'anatomy' => 5, 'object_integrity' => 5],
                hardFailTags: ['temporal_flicker'],
                reason: 'Flicker remains.',
                verdict: JudgementVerdict::Fail,
            ));

        (new JudgeVideoJob($shot->id, $brief->id))->handle($judge, $this->app->make(ShotStepRetry::class, ['agent' => $agent]), app(Budget::class));

        $this->assertSame([
            ['prompt' => 'Shot 1: original', 'reason' => 'Extreme temporal flicker'],
        ], $agent->contexts[0]['previous_attempts']);
        $this->assertSame('Shot 1: neon alley', $agent->contexts[0]['original_prompt']);
        $this->assertSame(BriefState::Rendering, $brief->refresh()->state);
        $this->assertSame(ShotState::Done, $other->refresh()->state);
        $this->assertSame(ShotState::GeneratingVideo, $shot->refresh()->state);
        $this->assertSame(3, $shot->attempts);
        $this->assertSame(2, $shot->metadata['step_attempts']['image']);
        $this->assertSame(2, $shot->metadata['step_attempts']['video']);

        Bus::assertDispatched(PollVideoRenderJob::class, function (PollVideoRenderJob $job) use ($shot): bool {
            return $job->shotId === $shot->id && $job->renderId === 'render-2' && $job->briefId === $shot->brief_id;
        });
    }

    /**
     * @param  array<string, int>  $scores
     */
    private function failingImageJudge(string $reason, array $scores, int $calls = 1): JudgeInterface
    {
        $judge = $this->createMock(JudgeInterface::class);
        $judge->expects($this->exactly($calls))
            ->method('judgeImage')
            ->willReturn(new JudgementResult(
                scores: $scores,
                hardFailTags: ['anatomy'],
                reason: $reason,
                verdict: JudgementVerdict::Fail,
            ));

        return $judge;
    }
}

class RecordingAgent implements AgentInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $contexts = [];

    public function planShots(string $prompt): array
    {
        return [];
    }

    public function generateStyleAnchor(string $prompt): string
    {
        return 'style';
    }

    public function rewritePrompt(array $context): string
    {
        $this->contexts[] = $context;

        return $context['original_prompt'].' [rewrite]';
    }
}
