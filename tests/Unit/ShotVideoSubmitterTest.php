<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\PollVideoRenderJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\RateLimitHitCounter;
use App\Services\ShotVideoSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ShotVideoSubmitterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Event::fake([VideoProgressUpdated::class, ShotUpdated::class]);
    }

    public function test_a_submitted_render_moves_the_shot_to_generating_video_and_returns_its_poll(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('submit')->willReturn('render-1');

        // Act
        $poll = app(ShotVideoSubmitter::class)->submit($shot, $videos);

        // Assert
        $this->assertInstanceOf(PollVideoRenderJob::class, $poll);
        $this->assertSame('render-1', $poll->renderId);
        $shot->refresh();
        $this->assertSame(ShotState::GeneratingVideo, $shot->state);
        $this->assertSame(1, $shot->attempts);
        $this->assertSame('render-1', $shot->metadata['render_id']);
        $this->assertSame(1, $brief->refresh()->budget_used);
        Event::assertDispatched(VideoProgressUpdated::class);
    }

    public function test_a_late_submit_counts_no_attempt_broadcasts_nothing_and_queues_no_poll(): void
    {
        // Arrange
        [, $shot] = $this->briefWithShot();
        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('submit')->willReturnCallback(function () use ($shot): string {
            Shot::query()->whereKey($shot->id)->update(['state' => ShotState::Failed->value]);

            return 'render-late';
        });

        // Act
        $poll = app(ShotVideoSubmitter::class)->submit($shot, $videos);

        // Assert
        $this->assertNull($poll);
        $shot->refresh();
        $this->assertSame(ShotState::Failed, $shot->state);
        $this->assertSame(0, $shot->attempts);
        $this->assertArrayNotHasKey('render_id', $shot->metadata ?? []);
        Event::assertNotDispatched(VideoProgressUpdated::class);
        Event::assertNotDispatched(ShotUpdated::class);
    }

    public function test_a_rate_limited_submit_refunds_counts_the_hit_and_leaves_the_shot_ready_to_resubmit(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('submit')->willThrowException(new ProviderRateLimitedException('HTTP 429'));

        // Act
        try {
            app(ShotVideoSubmitter::class)->submit($shot, $videos);
            $this->fail('The 429 was swallowed.');
        } catch (ProviderRateLimitedException) {
            // Assert
            $shot->refresh();
            $this->assertSame(0, $brief->refresh()->budget_used);
            $this->assertSame(0, $shot->attempts);
            $this->assertTrue($shot->state->canTransitionTo(ShotState::GeneratingVideo));
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('video'));
        }
    }

    public function test_a_submit_that_fails_any_other_way_keeps_its_charge_and_counts_an_attempt(): void
    {
        // Arrange
        [$brief, $shot] = $this->briefWithShot();
        $videos = $this->createMock(VideoProviderInterface::class);
        $videos->method('submit')->willThrowException(new RuntimeException('HTTP 500'));

        // Act
        try {
            app(ShotVideoSubmitter::class)->submit($shot, $videos);
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException) {
            // Assert
            $shot->refresh();
            $this->assertSame(1, $brief->refresh()->budget_used);
            $this->assertSame(1, $shot->attempts);
            $this->assertSame(1, $shot->metadata['step_attempts']['video']);
        }
    }

    /**
     * @return array{0: Brief, 1: Shot}
     */
    private function briefWithShot(): array
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser',
            'state' => BriefState::Rendering,
        ]);

        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1 prompt',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_1.png',
        ]);

        return [$brief, $shot];
    }
}
