<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\JudgeVideoJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class JudgeRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_judge_jobs_retry_rate_limits_for_two_hours_and_fail_other_errors(): void
    {
        config(['creative.judge.requests_per_minute' => 4]);

        $limit = RateLimiter::limiter(JudgeKeyframeJob::RATE_LIMITER);
        $this->assertNotNull($limit);
        $resolved = $limit();
        $this->assertSame(4, $resolved->maxAttempts);
        $this->assertSame(60, $resolved->decaySeconds);

        foreach ([JudgeKeyframeJob::class, JudgeVideoJob::class] as $class) {
            $job = new $class(1, 1);

            $this->assertSame('judge', $class::RATE_LIMITER);
            $this->assertTrue($job->failOnTimeout);
            $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
            $middleware = $job->middleware();
            $this->assertCount(2, $middleware);

            $limited = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof RateLimited);
            $throttle = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
            $this->assertInstanceOf(RateLimited::class, $limited);
            $this->assertInstanceOf(ThrottlesExceptions::class, $throttle);
            $this->assertSame('judge', (new ReflectionProperty($limited, 'limiterName'))->getValue($limited));
            $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

            $released = (new $class(1, 1))->withFakeQueueInteractions();
            $this->throttle($released)->handle($released, function (): void {
                throw new ProviderRateLimitedException('Gemini judge returned HTTP 429.');
            });
            $released->assertReleased();
            $released->assertNotFailed();

            $failed = (new $class(1, 1))->withFakeQueueInteractions();
            $this->throttle($failed)->handle($failed, function (): void {
                throw new RuntimeException('Gemini judge returned HTTP 500.');
            });
            $failed->assertFailed();
            $failed->assertNotReleased();
        }
    }

    public function test_rate_limited_image_judgement_refunds_and_a_retry_keeps_one_charge(): void
    {
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

        $judge = $this->judgeThatLimitsOnce('judgeImage');
        $job = new JudgeKeyframeJob($shot->id, $brief->id);

        try {
            $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $this->assertSame(0, $brief->budget_used);
            $this->assertSame([], $brief->metadata['budget_ledger'] ?? []);
            $this->assertSame(0, Judgement::query()->count());
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('judge'));
            $this->assertSame(BriefState::Keyframes, $brief->state);
        }

        $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));

        $brief->refresh();
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame([
            ['provider' => 'judge', 'operation' => 'judgeImage', 'cost' => 1, 'shot_id' => $shot->id],
        ], $brief->metadata['budget_ledger']);
        $this->assertSame(1, app(RateLimitHitCounter::class)->count('judge'));
        $this->assertSame(1, Judgement::query()->count());
        $saved = Judgement::query()->firstOrFail();
        $this->assertSame($shot->id, $saved->shot_id);
        $this->assertSame(JudgementStage::Image->value, $saved->stage);
        $this->assertSame(JudgementVerdict::Pass->value, $saved->verdict);
        $this->assertSame('Looks consistent.', $saved->reason);
    }

    public function test_rate_limited_video_judgement_refunds_and_a_retry_keeps_one_charge(): void
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Rendering,
        ]);
        $shot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: neon alley',
            'state' => ShotState::JudgingVideo,
            'video_path' => 'videos/shot_1.mp4',
        ]);

        $judge = $this->judgeThatLimitsOnce('judgeVideo');
        $job = new JudgeVideoJob($shot->id, $brief->id);

        try {
            $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $this->assertSame(0, $brief->budget_used);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('judge'));
        }

        $job->handle($judge, app(ShotStepRetry::class), app(Budget::class));

        $brief->refresh();
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame([
            ['provider' => 'judge', 'operation' => 'judgeVideo', 'cost' => 1, 'shot_id' => $shot->id],
        ], $brief->metadata['budget_ledger']);
        $this->assertSame(1, app(RateLimitHitCounter::class)->count('judge'));
        $this->assertSame(ShotState::Done, $shot->refresh()->state);
    }

    public function test_a_429_refunds_this_shots_charge_when_another_shot_charged_in_between(): void
    {
        // Arrange
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
        $otherShot = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: rooftop',
            'state' => ShotState::JudgingImage,
            'keyframe_path' => 'keyframes/shot_2.png',
        ]);
        $judge = $this->judgeThatLimitsAfter(fn () => app(Budget::class)->chargeShotStep($otherShot, 'judge', 'judgeImage'));

        // Act
        try {
            (new JudgeKeyframeJob($shot->id, $brief->id))
                ->handle($judge, app(ShotStepRetry::class), app(Budget::class), app(RateLimitHitCounter::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            // Expected.
        }

        // Assert
        $brief->refresh();
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame([
            ['provider' => 'judge', 'operation' => 'judgeImage', 'cost' => 1, 'shot_id' => $otherShot->id],
        ], $brief->metadata['budget_ledger']);
    }

    public function test_rate_limit_hits_survive_a_cache_flush(): void
    {
        // Arrange
        $counter = app(RateLimitHitCounter::class);
        $counter->increment('judge');
        $counter->increment('judge');

        // Act
        Cache::flush();

        // Assert
        $this->assertSame(2, $counter->count('judge'));
        $this->assertSame(0, $counter->count('audio'));
    }

    private function judgeThatLimitsAfter(Closure $during): JudgeInterface
    {
        return new class($during) implements JudgeInterface
        {
            public function __construct(private readonly Closure $during) {}

            public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                ($this->during)();

                throw new ProviderRateLimitedException('Gemini judge returned HTTP 429.');
            }

            public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                return $this->judgeImage($videoPath, $prompt, $styleAnchor);
            }
        };
    }

    private function throttle(ShouldQueue $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
    }

    private function judgeThatLimitsOnce(string $method): JudgeInterface
    {
        return new class($method) implements JudgeInterface
        {
            public int $calls = 0;

            public function __construct(private readonly string $method) {}

            public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                return $this->respond();
            }

            public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                return $this->respond();
            }

            private function respond(): JudgementResult
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new ProviderRateLimitedException('Gemini judge returned HTTP 429.');
                }

                $scores = [
                    'prompt_adherence' => 5,
                    'anatomy' => 5,
                    'object_integrity' => 5,
                ];

                if ($this->method === 'judgeVideo') {
                    $scores['temporal_consistency'] = 5;
                }

                return new JudgementResult(
                    scores: $scores,
                    hardFailTags: [],
                    reason: 'Looks consistent.',
                );
            }
        };
    }
}
