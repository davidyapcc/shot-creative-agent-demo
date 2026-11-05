<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Traits\FailsBriefOnJobFailure;
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
            $this->refundPlanningCharge($brief, $budget);
            $rateLimits->increment('agent');

            throw $exception;
        }
    }

    private function plan(Brief $brief, AgentInterface $agent, Budget $budget): void
    {
        $brief->refresh();

        if (! $this->hasStyleAnchor($brief) && ! $this->writeStyleAnchor($brief, $agent, $budget)) {
            return;
        }

        $brief->refresh();

        if ($brief->shots()->doesntExist() && ! $this->writeShots($brief, $agent, $budget)) {
            return;
        }

        $brief->refresh();

        if ($brief->state !== BriefState::Planning) {
            return;
        }

        $brief->transitionTo(BriefState::Keyframes);
        ProcessKeyframesJob::dispatch($brief->id);
    }

    private function writeStyleAnchor(Brief $brief, AgentInterface $agent, Budget $budget): bool
    {
        if (! $budget->chargeOrDegrade($brief, 'agent', 'generateStyleAnchor')) {
            return false;
        }

        if (! $this->claim($brief, 'style_anchor')) {
            $budget->refund($brief, 'agent', 'generateStyleAnchor');
            $brief->refresh();

            return $this->hasStyleAnchor($brief);
        }

        try {
            $anchor = $agent->generateStyleAnchor($brief->prompt);
        } catch (ProviderRateLimitedException $exception) {
            $this->releaseClaim($brief, 'style_anchor');

            throw $exception;
        }

        $this->saveStyleAnchor($brief, $anchor);

        return true;
    }

    private function writeShots(Brief $brief, AgentInterface $agent, Budget $budget): bool
    {
        if (! $budget->chargeOrDegrade($brief, 'agent', 'planShots')) {
            return false;
        }

        if (! $this->claim($brief, 'plan_shots')) {
            $budget->refund($brief, 'agent', 'planShots');
            $brief->refresh();

            return $brief->shots()->exists();
        }

        try {
            $plannedShots = $agent->planShots($brief->prompt);
        } catch (ProviderRateLimitedException $exception) {
            $this->releaseClaim($brief, 'plan_shots');

            throw $exception;
        }

        $shots = DB::transaction(function () use ($brief, $plannedShots): array {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $created = [];

            foreach ($plannedShots as $shotData) {
                $created[] = Shot::create([
                    'brief_id' => $brief->id,
                    'sequence' => $shotData['sequence'],
                    'prompt' => $shotData['prompt'],
                    'state' => ShotState::Pending,
                ]);
            }

            $metadata = $locked->metadata ?? [];
            unset($metadata['agent_claims']['plan_shots']);
            $locked->update(['metadata' => $metadata]);

            return $created;
        });

        foreach ($shots as $shot) {
            ShotUpdated::dispatch($shot);
        }

        return true;
    }

    /**
     * Reserve this step under a row lock so a second worker does not pay for it.
     */
    private function claim(Brief $brief, string $step): bool
    {
        return DB::transaction(function () use ($brief, $step): bool {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state !== BriefState::Planning) {
                return false;
            }

            if ($step === 'style_anchor' && $this->hasStyleAnchor($locked)) {
                return false;
            }

            if ($step === 'plan_shots' && $locked->shots()->exists()) {
                return false;
            }

            $metadata = $locked->metadata ?? [];
            $claims = $metadata['agent_claims'] ?? [];

            if (($claims[$step] ?? false) === true) {
                return false;
            }

            $claims[$step] = true;
            $metadata['agent_claims'] = $claims;
            $locked->update(['metadata' => $metadata]);

            return true;
        });
    }

    private function releaseClaim(Brief $brief, string $step): void
    {
        DB::transaction(function () use ($brief, $step): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $metadata = $locked->metadata ?? [];
            unset($metadata['agent_claims'][$step]);
            $locked->update(['metadata' => $metadata]);
        });
    }

    private function saveStyleAnchor(Brief $brief, string $anchor): void
    {
        DB::transaction(function () use ($brief, $anchor): void {
            $locked = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            unset($metadata['agent_claims']['style_anchor']);

            $locked->update([
                'style_anchor' => $anchor,
                'metadata' => $metadata,
            ]);
        });

        $brief->refresh();
    }

    /**
     * Give back the call the provider refused. A saved style anchor means the
     * refused call was planning, not the anchor.
     */
    private function refundPlanningCharge(Brief $brief, Budget $budget): void
    {
        $brief->refresh();

        $budget->refund(
            $brief,
            'agent',
            $this->hasStyleAnchor($brief) ? 'planShots' : 'generateStyleAnchor',
        );
    }

    private function hasStyleAnchor(Brief $brief): bool
    {
        return is_string($brief->style_anchor) && $brief->style_anchor !== '';
    }
}
