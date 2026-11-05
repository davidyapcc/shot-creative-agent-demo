<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\DTOs\ShotPlan;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Traits\FailsBriefOnJobFailure;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlanBriefJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;

    public const RATE_LIMITER = 'agent';

    private const RETRY_WINDOW_HOURS = 2;

    private const THROTTLE_MAX_EXCEPTIONS = 3;

    private const THROTTLE_DECAY_SECONDS = 300;

    private const THROTTLE_BACKOFF_MINUTES = 1;

    /**
     * Ledger operation for the one paid planning call.
     */
    private const PLAN_OPERATION = 'planShots';

    /**
     * Brief metadata flag held while a worker's plan call is in flight.
     */
    private const CLAIM_KEY = 'planning_claimed';

    /**
     * A timed-out run already took its charge, so a retry would pay twice.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $briefId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited(self::RATE_LIMITER),
            (new ThrottlesExceptions(self::THROTTLE_MAX_EXCEPTIONS, self::THROTTLE_DECAY_SECONDS))
                ->backoff(self::THROTTLE_BACKOFF_MINUTES)
                ->failWhen(fn (Throwable $exception): bool => ! $exception instanceof ProviderRateLimitedException),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    /**
     * The cache key RateLimited uses for this job, so a rewrite can share it.
     */
    public static function rateLimitKey(): string
    {
        return md5(self::RATE_LIMITER);
    }

    public function handle(AgentInterface $agent, Budget $budget, ?RateLimitHitCounter $rateLimits = null): void
    {
        $rateLimits ??= new RateLimitHitCounter;
        $brief = Brief::findOrFail($this->briefId);

        if ($brief->state === BriefState::Pending) {
            $brief->transitionTo(BriefState::Planning);
        }

        if ($brief->state !== BriefState::Planning) {
            return;
        }

        try {
            $this->plan($brief, $agent, $budget);
        } catch (ProviderRateLimitedException $exception) {
            $rateLimits->increment('agent');

            throw $exception;
        }
    }

    private function plan(Brief $brief, AgentInterface $agent, Budget $budget): void
    {
        if ($brief->shots()->doesntExist() && ! $this->writePlan($brief, $agent, $budget)) {
            return;
        }

        $brief->refresh();

        if ($brief->state !== BriefState::Planning) {
            return;
        }

        $brief->transitionTo(BriefState::Keyframes);
        ProcessKeyframesJob::dispatch($brief->id);
    }

    /**
     * Charge, claim, call the agent once, then store the plan. False when
     * nothing was stored: the budget stopped it, another worker holds the
     * claim, or the brief failed during the call.
     */
    private function writePlan(Brief $brief, AgentInterface $agent, Budget $budget): bool
    {
        if (! $budget->chargeOrDegrade($brief, 'agent', self::PLAN_OPERATION)) {
            return false;
        }

        if (! $this->claim($brief)) {
            $budget->refund($brief, 'agent', self::PLAN_OPERATION);

            return $brief->shots()->exists();
        }

        try {
            $plan = $agent->planShots($brief->prompt);
        } catch (ProviderRateLimitedException $exception) {
            // A refused request spent no quota.
            $this->releaseClaim($brief);
            $budget->refund($brief, 'agent', self::PLAN_OPERATION);

            throw $exception;
        }

        $shots = $this->savePlan($brief, $plan);

        foreach ($shots ?? [] as $shot) {
            ShotUpdated::dispatch($shot);
        }

        return $shots !== null;
    }

    /**
     * Reserve the plan call under a row lock so a second worker does not pay for it.
     */
    private function claim(Brief $brief): bool
    {
        return $this->withLockedBrief($brief, function (Brief $locked): bool {
            if ($locked->state !== BriefState::Planning
                || $locked->shots()->exists()
                || ($locked->metadata[self::CLAIM_KEY] ?? false) === true) {
                return false;
            }

            $locked->update(['metadata' => [...$locked->metadata ?? [], self::CLAIM_KEY => true]]);

            return true;
        });
    }

    private function releaseClaim(Brief $brief): void
    {
        $this->withLockedBrief($brief, function (Brief $locked): void {
            $locked->update(['metadata' => $this->withoutClaim($locked)]);
        });
    }

    /**
     * Store the anchor and shots together and clear the claim. Null when the
     * brief failed while the call ran; the late plan is dropped.
     *
     * @return list<Shot>|null
     */
    private function savePlan(Brief $brief, ShotPlan $plan): ?array
    {
        return $this->withLockedBrief($brief, function (Brief $locked) use ($plan): ?array {
            if ($locked->state !== BriefState::Planning) {
                $locked->update(['metadata' => $this->withoutClaim($locked)]);

                return null;
            }

            $locked->update([
                'style_anchor' => $plan->styleAnchor,
                'metadata' => $this->withoutClaim($locked),
            ]);

            return array_map(fn (array $shot): Shot => Shot::create([
                'brief_id' => $locked->id,
                'sequence' => $shot['sequence'],
                'prompt' => $shot['prompt'],
                'state' => ShotState::Pending,
            ]), $plan->shots);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Brief): T  $callback
     * @return T
     */
    private function withLockedBrief(Brief $brief, Closure $callback): mixed
    {
        return DB::transaction(function () use ($brief, $callback): mixed {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutClaim(Brief $brief): array
    {
        return array_diff_key($brief->metadata ?? [], [self::CLAIM_KEY => true]);
    }
}
