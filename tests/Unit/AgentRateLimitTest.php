<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\PlanBriefJob;
use App\Jobs\ProcessKeyframesJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class AgentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_is_rate_limited_like_other_provider_jobs(): void
    {
        config(['creative.agent.requests_per_minute' => 4]);

        $limit = RateLimiter::limiter(PlanBriefJob::RATE_LIMITER);
        $this->assertNotNull($limit);
        $resolved = $limit();
        $this->assertSame(4, $resolved->maxAttempts);
        $this->assertSame(60, $resolved->decaySeconds);

        $job = new PlanBriefJob(1);

        $this->assertSame('agent', PlanBriefJob::RATE_LIMITER);
        $this->assertTrue($job->failOnTimeout);
        $middleware = $job->middleware();
        $this->assertCount(2, $middleware);

        $limited = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof RateLimited);
        $throttle = collect($middleware)->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
        $this->assertInstanceOf(RateLimited::class, $limited);
        $this->assertInstanceOf(ThrottlesExceptions::class, $throttle);
        $this->assertSame('agent', (new ReflectionProperty($limited, 'limiterName'))->getValue($limited));
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

        $released = (new PlanBriefJob(1))->withFakeQueueInteractions();
        $this->throttle($released)->handle($released, function (): void {
            throw new ProviderRateLimitedException('Gemini agent returned HTTP 429.');
        });
        $released->assertReleased();
        $released->assertNotFailed();

        $failed = (new PlanBriefJob(1))->withFakeQueueInteractions();
        $this->throttle($failed)->handle($failed, function (): void {
            throw new RuntimeException('Gemini agent returned HTTP 500.');
        });
        $failed->assertFailed();
        $failed->assertNotReleased();
    }

    public function test_rate_limited_style_anchor_is_refunded_and_a_retry_can_plan(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Pending,
        ]);
        $agent = new RateLimitedPlanningAgent(failStyleTimes: 1);

        try {
            (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class), app(RateLimitHitCounter::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $this->assertSame(BriefState::Planning, $brief->state);
            $this->assertNull($brief->style_anchor);
            $this->assertSame(0, $brief->budget_used);
            $this->assertSame([], $brief->metadata['budget_ledger'] ?? []);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }

        (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class), app(RateLimitHitCounter::class));

        $brief->refresh();
        $this->assertSame(2, $agent->styleCalls);
        $this->assertSame(1, $agent->planCalls);
        $this->assertSame('Cinematic neon rain.', $brief->style_anchor);
        $this->assertCount(2, $brief->shots);
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame('generateStyleAnchor', $brief->metadata['budget_ledger'][0]['operation']);
        $this->assertSame('planShots', $brief->metadata['budget_ledger'][1]['operation']);
        $this->assertSame(BriefState::Keyframes, $brief->state);
    }

    public function test_rate_limited_plan_keeps_the_style_anchor_and_a_retry_does_not_pay_for_it_again(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Pending,
        ]);
        $agent = new RateLimitedPlanningAgent(failPlanTimes: 1);

        try {
            (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class), app(RateLimitHitCounter::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $this->assertSame('Cinematic neon rain.', $brief->style_anchor);
            $this->assertSame(1, $brief->budget_used);
            $this->assertSame('generateStyleAnchor', $brief->metadata['budget_ledger'][0]['operation']);
            $this->assertCount(0, $brief->shots);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }

        (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class), app(RateLimitHitCounter::class));

        $brief->refresh();
        $this->assertSame(1, $agent->styleCalls);
        $this->assertSame(2, $agent->planCalls);
        $this->assertCount(2, $brief->shots);
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame(BriefState::Keyframes, $brief->state);
    }

    public function test_prism_planning_stores_the_style_anchor_and_meters_both_calls(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);

        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
            ]),
            StructuredResponseFake::make()->withStructured([
                'shots' => [
                    ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
                    ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
                    ['sequence' => 3, 'prompt' => 'Detail of the cup.'],
                ],
            ]),
        ]);

        config([
            'creative.agent.provider' => 'gemini',
            'creative.agent.model' => 'gemini-3.8-flash',
            'services.gemini.key' => 'test-key',
        ]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Pending,
        ]);

        (new PlanBriefJob($brief->id))->handle(app(AgentInterface::class), app(Budget::class));

        $brief->refresh();
        $this->assertSame('Cinematic neon rain.', $brief->style_anchor);
        $this->assertCount(3, $brief->shots);
        $this->assertSame('Wide shot of rainy Tokyo.', $brief->shots[0]->prompt);
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame(
            ['generateStyleAnchor', 'planShots'],
            array_column($brief->metadata['budget_ledger'], 'operation'),
        );
        $this->assertSame('agent', $brief->metadata['budget_ledger'][0]['provider']);
        Queue::assertPushed(ProcessKeyframesJob::class);
    }

    public function test_rate_limited_rewrite_refunds_and_a_retry_does_not_judge_again(): void
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
        $agent = new RateLimitedRewriteAgent;
        $judge = new CountingFailJudge;
        $retry = $this->app->make(ShotStepRetry::class, ['agent' => $agent]);
        $job = new JudgeKeyframeJob($shot->id, $brief->id);

        try {
            $job->handle($judge, $retry, app(Budget::class), app(RateLimitHitCounter::class));
            $this->fail('The rate-limit error should escape the job.');
        } catch (ProviderRateLimitedException) {
            $brief->refresh();
            $shot->refresh();
            $this->assertSame(1, $judge->calls);
            $this->assertSame(1, $shot->judgements()->count());
            $this->assertSame('Shot 1: neon alley', $shot->prompt);
            $this->assertSame(1, $brief->budget_used);
            $this->assertSame('judge', $brief->metadata['budget_ledger'][0]['provider']);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }

        $job->handle($judge, $retry, app(Budget::class), app(RateLimitHitCounter::class));

        $shot->refresh();
        $brief->refresh();
        $this->assertSame(1, $judge->calls);
        $this->assertSame(2, $agent->rewrites);
        $this->assertSame('Shot 1: neon alley. Simpler hand.', $shot->prompt);
        $this->assertSame(2, $brief->budget_used);
        $this->assertSame(1, $shot->judgements()->count());
        Bus::assertChained([
            function (GenerateKeyframeJob $generate) use ($shot): bool {
                return $generate->shotId === $shot->id;
            },
            function (JudgeKeyframeJob $judgeJob) use ($shot): bool {
                return $judgeJob->shotId === $shot->id;
            },
        ]);
    }

    private function throttle(PlanBriefJob $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
    }
}

class RateLimitedPlanningAgent implements AgentInterface
{
    public int $styleCalls = 0;

    public int $planCalls = 0;

    public function __construct(
        private readonly int $failStyleTimes = 0,
        private readonly int $failPlanTimes = 0,
    ) {}

    public function planShots(string $prompt): array
    {
        $this->planCalls++;

        if ($this->planCalls <= $this->failPlanTimes) {
            throw new ProviderRateLimitedException('Gemini agent returned HTTP 429.');
        }

        return [
            ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
            ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
        ];
    }

    public function generateStyleAnchor(string $prompt): string
    {
        $this->styleCalls++;

        if ($this->styleCalls <= $this->failStyleTimes) {
            throw new ProviderRateLimitedException('Gemini agent returned HTTP 429.');
        }

        return 'Cinematic neon rain.';
    }

    public function rewritePrompt(array $context): string
    {
        return $context['original_prompt'];
    }
}

class RateLimitedRewriteAgent implements AgentInterface
{
    public int $rewrites = 0;

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
        $this->rewrites++;

        if ($this->rewrites === 1) {
            throw new ProviderRateLimitedException('Gemini agent returned HTTP 429.');
        }

        return $context['original_prompt'].'. Simpler hand.';
    }
}

class CountingFailJudge implements JudgeInterface
{
    public int $calls = 0;

    public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        $this->calls++;

        return new JudgementResult(
            scores: ['anatomy' => 2],
            hardFailTags: ['anatomy'],
            reason: 'Left hand has six fingers.',
            verdict: JudgementVerdict::Fail,
        );
    }

    public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        return $this->judgeImage($videoPath, $prompt, $styleAnchor);
    }
}
