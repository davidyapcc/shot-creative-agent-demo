<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\VideoProviderInterface;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\PollVideoRenderJob;
use App\Jobs\SubmitVideoRenderJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use App\Services\ShotVideoSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class SubmitVideoRenderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([VideoProgressUpdated::class, ShotUpdated::class]);
    }

    public function test_submit_job_is_rate_limited_and_retries_only_rate_limits_for_two_hours(): void
    {
        // Arrange
        config(['creative.video_requests_per_minute' => 4]);

        // Act
        $resolved = RateLimiter::limiter(SubmitVideoRenderJob::RATE_LIMITER)();
        $job = new SubmitVideoRenderJob(1, 1, 0);
        $middleware = collect($job->middleware());
        $limited = $middleware->first(fn (object $item): bool => $item instanceof RateLimited);

        // Assert
        $this->assertSame('video', SubmitVideoRenderJob::RATE_LIMITER);
        $this->assertSame(4, $resolved->maxAttempts);
        $this->assertSame(60, $resolved->decaySeconds);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame('generate', $job->queue);
        $this->assertInstanceOf(RateLimited::class, $limited);
        $this->assertSame('video', (new ReflectionProperty($limited, 'limiterName'))->getValue($limited));
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

        $failed = (new SubmitVideoRenderJob(1, 1, 0))->withFakeQueueInteractions();
        $this->throttle($failed)->handle($failed, function (): void {
            throw new RuntimeException('Video provider returned HTTP 500.');
        });
        $failed->assertFailed();
        $failed->assertNotReleased();
    }

    public function test_a_rate_limited_first_submit_refunds_retries_later_and_renders_the_shot(): void
    {
        // Arrange
        Bus::fake([PollVideoRenderJob::class]);
        [$brief, $shot] = $this->briefWithShot(ShotState::JudgingImage);
        $videos = $this->bindVideos(new RateLimitedOnceVideoProvider);

        // Act
        $first = $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 0));

        // Assert: the 429 cost nothing and the job waits for a retry
        $first->assertReleased();
        $first->assertNotFailed();
        $this->assertSame(0, $brief->refresh()->budget_used);
        $this->assertSame(0, $shot->refresh()->attempts);
        $this->assertSame(ShotState::JudgingImage, $shot->state);
        $this->assertSame(1, app(RateLimitHitCounter::class)->count('video'));
        Bus::assertNotDispatched(PollVideoRenderJob::class);

        // Act: the queue runs the released job again
        $retry = $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 0));

        // Assert
        $retry->assertNotReleased();
        $retry->assertNotFailed();
        $this->assertSame(2, $videos->submits);
        $this->assertSame(1, $brief->refresh()->budget_used);
        $shot->refresh();
        $this->assertSame(ShotState::GeneratingVideo, $shot->state);
        $this->assertSame('render-2', $shot->metadata['render_id']);
        $this->assertSame(1, $shot->attempts);
        Bus::assertDispatchedTimes(PollVideoRenderJob::class, 1);
    }

    public function test_a_rate_limited_resubmit_after_a_rewrite_retries_later_and_renders_the_shot(): void
    {
        // Arrange
        Bus::fake([PollVideoRenderJob::class, SubmitVideoRenderJob::class]);
        [$brief, $shot] = $this->briefWithShot(ShotState::JudgingVideo, videoAttempts: 1);
        $videos = $this->bindVideos(new RateLimitedOnceVideoProvider);

        // Act: the rewrite hands the resubmit to its own job instead of calling the provider
        app(ShotStepRetry::class)->retryStep($shot, JudgementStage::Video, null);

        // Assert
        $this->assertSame(0, $videos->submits);
        Bus::assertDispatched(SubmitVideoRenderJob::class, fn (SubmitVideoRenderJob $job): bool => $job->shotId === $shot->id && $job->priorVideoAttempts === 1);

        // Act: the submit is rate-limited once, then retried
        $first = $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 1));
        $retry = $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 1));

        // Assert
        $first->assertReleased();
        $retry->assertNotReleased();
        $retry->assertNotFailed();
        $this->assertSame(1, $brief->refresh()->budget_used);
        $shot->refresh();
        $this->assertSame(ShotState::GeneratingVideo, $shot->state);
        $this->assertSame(2, $shot->metadata['step_attempts']['video']);
        Bus::assertDispatchedTimes(PollVideoRenderJob::class, 1);
    }

    public function test_a_second_copy_of_a_submit_never_pays_twice(): void
    {
        // Arrange
        Bus::fake([PollVideoRenderJob::class]);
        [$brief, $shot] = $this->briefWithShot(ShotState::JudgingImage);
        $videos = $this->bindVideos(new RateLimitedOnceVideoProvider(rateLimitFirst: false));

        // Act
        $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 0));
        $this->runThroughMiddleware(new SubmitVideoRenderJob($shot->id, $brief->id, 0));

        // Assert
        $this->assertSame(1, $videos->submits);
        $this->assertSame(1, $shot->refresh()->attempts);
        $this->assertSame(1, $brief->refresh()->budget_used);
        Bus::assertDispatchedTimes(PollVideoRenderJob::class, 1);
    }

    public function test_a_rate_limited_shot_leaves_the_other_shots_submits_and_polls_alone(): void
    {
        // Arrange
        Bus::fake([PollVideoRenderJob::class]);
        [$brief, $limitedShot] = $this->briefWithShot(ShotState::JudgingImage);
        $otherShot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2 prompt',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_2.png',
        ]);
        $this->bindVideos(new RateLimitedOnceVideoProvider);

        // Act
        $limited = $this->runThroughMiddleware(new SubmitVideoRenderJob($limitedShot->id, $brief->id, 0));
        $other = $this->runThroughMiddleware(new SubmitVideoRenderJob($otherShot->id, $brief->id, 0));

        // Assert
        $limited->assertReleased();
        $other->assertNotReleased();
        $other->assertNotFailed();
        $this->assertSame(ShotState::JudgingImage, $limitedShot->refresh()->state);
        $this->assertSame(ShotState::GeneratingVideo, $otherShot->refresh()->state);
        $this->assertSame(BriefState::Rendering, $brief->refresh()->state);
        Bus::assertDispatched(PollVideoRenderJob::class, fn (PollVideoRenderJob $poll): bool => $poll->shotId === $otherShot->id);
        Bus::assertDispatchedTimes(PollVideoRenderJob::class, 1);
    }

    public function test_a_submit_in_the_render_batch_adds_its_poll_to_that_batch(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot(ShotState::JudgingImage);
        $this->bindVideos(new RateLimitedOnceVideoProvider(rateLimitFirst: false));
        [$job, $batch] = (new SubmitVideoRenderJob($shot->id, $brief->id, 0))->withFakeBatch();

        // Act
        $job->handle(app(VideoProviderInterface::class), app(ShotVideoSubmitter::class));

        // Assert
        $this->assertCount(1, $batch->added);
        $this->assertInstanceOf(PollVideoRenderJob::class, $batch->added[0]);
        $this->assertSame($shot->id, $batch->added[0]->shotId);
    }

    private function runThroughMiddleware(SubmitVideoRenderJob $job): SubmitVideoRenderJob
    {
        $job->withFakeQueueInteractions();

        $this->throttle($job)->handle($job, function (SubmitVideoRenderJob $job): void {
            $job->handle(app(VideoProviderInterface::class), app(ShotVideoSubmitter::class));
        });

        return $job;
    }

    private function throttle(SubmitVideoRenderJob $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
    }

    private function bindVideos(RateLimitedOnceVideoProvider $videos): RateLimitedOnceVideoProvider
    {
        $this->app->instance(VideoProviderInterface::class, $videos);

        return $videos;
    }

    /**
     * @return array{0: Brief, 1: Shot}
     */
    private function briefWithShot(ShotState $state, int $videoAttempts = 0): array
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1 prompt',
            'state' => $state,
            'keyframe_path' => 'keyframes/shot_1.png',
            'attempts' => $videoAttempts,
            'metadata' => $videoAttempts > 0 ? ['step_attempts' => ['video' => $videoAttempts]] : null,
        ]);

        return [$brief, $shot];
    }
}

/**
 * Refuses its first submit with a 429, then accepts every submit after it.
 */
class RateLimitedOnceVideoProvider implements VideoProviderInterface
{
    public int $submits = 0;

    public function __construct(
        private bool $rateLimitFirst = true,
    ) {}

    public function submit(string $prompt, string $keyframePath): string
    {
        $this->submits++;

        if ($this->rateLimitFirst) {
            $this->rateLimitFirst = false;

            throw new ProviderRateLimitedException('Video provider returned HTTP 429.');
        }

        return "render-{$this->submits}";
    }

    public function poll(string $renderId): VideoRenderResult
    {
        throw new LogicException('Polls are not part of these tests.');
    }
}
