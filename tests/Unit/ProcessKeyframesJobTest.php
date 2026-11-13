<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\AdvanceBriefToRenderingCallback;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\ProcessKeyframesJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use RuntimeException;
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

        Bus::assertBatched(function (PendingBatch $batch) {
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

        $job->handle($imageProvider, app(Budget::class));

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

        $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));

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
        $job->handle($failingJudge, app(ShotStepRetry::class), app(Budget::class));

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

    public function test_generate_keyframe_job_is_rate_limited_and_retries_only_rate_limits_for_two_hours(): void
    {
        config(['creative.image_requests_per_minute' => 4]);

        $limit = RateLimiter::limiter(GenerateKeyframeJob::RATE_LIMITER);
        $this->assertNotNull($limit);
        $resolved = $limit();
        $this->assertSame(4, $resolved->maxAttempts);
        $this->assertSame(60, $resolved->decaySeconds);

        $job = new GenerateKeyframeJob(1, 1);

        $this->assertSame('image', GenerateKeyframeJob::RATE_LIMITER);
        $this->assertTrue($job->failOnTimeout);
        $middleware = $job->middleware();
        $this->assertCount(2, $middleware);

        $limited = collect($middleware)->first(fn (object $item): bool => $item instanceof RateLimited);
        $throttle = collect($middleware)->first(fn (object $item): bool => $item instanceof ThrottlesExceptions);
        $this->assertInstanceOf(RateLimited::class, $limited);
        $this->assertInstanceOf(ThrottlesExceptions::class, $throttle);
        $this->assertSame('image', (new ReflectionProperty($limited, 'limiterName'))->getValue($limited));
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

        $released = (new GenerateKeyframeJob(1, 1))->withFakeQueueInteractions();
        $throttle->handle($released, function (): void {
            throw new ProviderRateLimitedException('Cloudflare image provider returned HTTP 429.');
        });
        $released->assertReleased();
        $released->assertNotFailed();

        $failed = (new GenerateKeyframeJob(1, 1))->withFakeQueueInteractions();
        $throttle->handle($failed, function (): void {
            throw new RuntimeException('Cloudflare image provider returned HTTP 500.');
        });
        $failed->assertFailed();
        $failed->assertNotReleased();
    }

    public function test_rate_limited_keyframe_refunds_shot_charge_counts_429_and_does_not_increment_attempts(): void
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

        $otherShot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: rooftop',
            'state' => ShotState::Pending,
        ]);

        $provider = new class($otherShot) implements ImageProviderInterface
        {
            public int $calls = 0;

            public function __construct(private readonly Shot $otherShot) {}

            public function generateImage(string $prompt, ?string $styleAnchor = null): string
            {
                $this->calls++;

                if ($this->calls === 1) {
                    app(Budget::class)->chargeShotStep($this->otherShot, 'image', 'generateImage');

                    throw new ProviderRateLimitedException('Cloudflare image provider returned HTTP 429.');
                }

                return "\x89PNG\r\n\x1a\n".'keyframe-bytes';
            }
        };

        $job = new GenerateKeyframeJob($shot->id, $brief->id);

        try {
            $job->handle($provider, app(Budget::class), app(RateLimitHitCounter::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $shot->refresh();

            $this->assertSame(1, $brief->budget_used);
            $this->assertSame([
                ['provider' => 'image', 'operation' => 'generateImage', 'cost' => 1, 'shot_id' => $otherShot->id],
            ], $brief->metadata['budget_ledger']);
            $this->assertSame(0, $shot->attempts);
            $this->assertArrayNotHasKey('step_attempts', $shot->metadata ?? []);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('image'));
        }

        $job->handle($provider, app(Budget::class), app(RateLimitHitCounter::class));

        $brief->refresh();
        $shot->refresh();

        $this->assertSame(2, $provider->calls);
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame([
            ['provider' => 'image', 'operation' => 'generateImage', 'cost' => 1, 'shot_id' => $otherShot->id],
            ['provider' => 'image', 'operation' => 'generateImage', 'cost' => 1, 'shot_id' => $shot->id],
        ], $brief->metadata['budget_ledger']);
        $this->assertSame(1, $shot->attempts);
        $this->assertSame(1, $shot->metadata['step_attempts']['image']);
        $this->assertSame(ShotState::JudgingImage, $shot->state);
        $this->assertSame(1, app(RateLimitHitCounter::class)->count('image'));
    }
}
