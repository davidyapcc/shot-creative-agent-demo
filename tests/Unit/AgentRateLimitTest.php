<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\DTOs\ShotPlan;
use App\Enums\BriefState;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\PlanBriefJob;
use App\Jobs\ProcessKeyframesJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Agent\MockAgent;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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

    public function test_rate_limited_plan_is_refunded_and_a_retry_plans_once(): void
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
            $this->assertSame(BriefState::Planning, $brief->state);
            $this->assertNull($brief->style_anchor);
            $this->assertSame(0, $brief->budget_used);
            $this->assertSame([], $brief->metadata['budget_ledger'] ?? []);
            $this->assertArrayNotHasKey('planning_claimed', $brief->metadata ?? []);
            $this->assertSame(1, app(RateLimitHitCounter::class)->count('agent'));
        }

        (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class), app(RateLimitHitCounter::class));

        $brief->refresh();
        $this->assertSame(2, $agent->planCalls);
        $this->assertSame('Cinematic neon rain.', $brief->style_anchor);
        $this->assertCount(2, $brief->shots);
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame(['planShots'], array_column($brief->metadata['budget_ledger'], 'operation'));
        $this->assertSame(BriefState::Keyframes, $brief->state);
    }

    public function test_prism_planning_stores_the_style_anchor_and_shots_from_one_metered_call(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);

        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
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
        $this->assertSame(1, $brief->budget_used);
        $this->assertSame(['planShots'], array_column($brief->metadata['budget_ledger'], 'operation'));
        $this->assertSame('agent', $brief->metadata['budget_ledger'][0]['provider']);
        $this->assertArrayNotHasKey('planning_claimed', $brief->metadata);
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

    public function test_a_plan_finished_after_the_brief_failed_is_dropped(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);
        Event::fake([ShotUpdated::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Planning,
        ]);

        (new PlanBriefJob($brief->id))->handle(new BriefFailingPlanningAgent($brief), app(Budget::class));

        $brief->refresh();
        $this->assertSame(BriefState::Failed, $brief->state);
        $this->assertNull($brief->style_anchor);
        $this->assertSame(0, $brief->shots()->count());
        $this->assertArrayNotHasKey('planning_claimed', $brief->metadata);
        Event::assertNotDispatched(ShotUpdated::class);
        Queue::assertNotPushed(ProcessKeyframesJob::class);
    }

    public function test_a_planning_claim_already_held_skips_the_call_and_refunds(): void
    {
        Queue::fake([ProcessKeyframesJob::class]);

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Planning,
            'metadata' => ['planning_claimed' => true],
        ]);
        $agent = new RateLimitedPlanningAgent;

        (new PlanBriefJob($brief->id))->handle($agent, app(Budget::class));

        $brief->refresh();
        $this->assertSame(0, $agent->planCalls);
        $this->assertSame(0, $brief->budget_used);
        $this->assertSame([], $brief->metadata['budget_ledger'] ?? []);
    }

    public function test_a_rewrite_finished_after_the_shot_stopped_is_dropped(): void
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
        $agent = new LateRewriteAgent($shot);

        (new JudgeKeyframeJob($shot->id, $brief->id))->handle(
            new CountingFailJudge,
            $this->app->make(ShotStepRetry::class, ['agent' => $agent]),
            app(Budget::class),
        );

        $shot->refresh();
        $this->assertSame(ShotState::Failed, $shot->state);
        $this->assertSame('Shot 1: neon alley', $shot->prompt);
        $this->assertSame(2, $brief->refresh()->budget_used);
        Bus::assertNotDispatched(GenerateKeyframeJob::class);
    }

    public function test_rewrite_waits_on_the_agent_rate_limit_without_calling_or_judging_again(): void
    {
        Bus::fake();
        config(['creative.agent.requests_per_minute' => 1]);
        RateLimiter::hit(PlanBriefJob::rateLimitKey(), 60);

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
        $agent = new SteadyRewriteAgent;
        $judge = new CountingFailJudge;
        $retry = $this->app->make(ShotStepRetry::class, ['agent' => $agent]);
        $job = new JudgeKeyframeJob($shot->id, $brief->id);

        try {
            $job->handle($judge, $retry, app(Budget::class));
            $this->fail('The agent rate limit should release the rewrite.');
        } catch (ProviderRateLimitedException) {
            $this->assertSame(0, $agent->rewrites);
            $this->assertSame(1, $judge->calls);
            $this->assertSame(1, $brief->refresh()->budget_used);
        }

        RateLimiter::clear(PlanBriefJob::rateLimitKey());
        $job->handle($judge, $retry, app(Budget::class));

        $shot->refresh();
        $this->assertSame(1, $judge->calls);
        $this->assertSame(1, $agent->rewrites);
        $this->assertSame('Shot 1: neon alley. Simpler hand.', $shot->prompt);
        $this->assertSame(2, $brief->refresh()->budget_used);
    }

    private function throttle(PlanBriefJob $job): ThrottlesExceptions
    {
        return collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ThrottlesExceptions);
    }
}

class RateLimitedPlanningAgent implements AgentInterface
{
    public int $planCalls = 0;

    public function __construct(
        private readonly int $failPlanTimes = 0,
    ) {}

    public function planShots(string $prompt): ShotPlan
    {
        $this->planCalls++;

        if ($this->planCalls <= $this->failPlanTimes) {
            throw new ProviderRateLimitedException('Gemini agent returned HTTP 429.');
        }

        return new ShotPlan('Cinematic neon rain.', [
            ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
            ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
        ]);
    }

    public function rewritePrompt(array $context): string
    {
        return $context['original_prompt'];
    }
}

/**
 * Fails the brief while the plan call is running, as the budget stop or a
 * failed sibling job would.
 */
class BriefFailingPlanningAgent implements AgentInterface
{
    public function __construct(private readonly Brief $brief) {}

    public function planShots(string $prompt): ShotPlan
    {
        $this->brief->refresh()->failWithInFlightShots();

        return (new MockAgent)->planShots($prompt);
    }

    public function rewritePrompt(array $context): string
    {
        return $context['original_prompt'];
    }
}

class RateLimitedRewriteAgent implements AgentInterface
{
    public int $rewrites = 0;

    public function planShots(string $prompt): ShotPlan
    {
        return (new MockAgent)->planShots($prompt);
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

class LateRewriteAgent implements AgentInterface
{
    public function __construct(private readonly Shot $shot) {}

    public function planShots(string $prompt): ShotPlan
    {
        return (new MockAgent)->planShots($prompt);
    }

    public function rewritePrompt(array $context): string
    {
        $this->shot->refresh();
        $this->shot->transitionTo(ShotState::Failed);

        return 'Shot 1: neon alley. Late rewrite.';
    }
}

class SteadyRewriteAgent implements AgentInterface
{
    public int $rewrites = 0;

    public function planShots(string $prompt): ShotPlan
    {
        return (new MockAgent)->planShots($prompt);
    }

    public function rewritePrompt(array $context): string
    {
        $this->rewrites++;

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
