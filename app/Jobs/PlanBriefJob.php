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
        if (! $this->hasStyleAnchor($brief)) {
            if (! $budget->chargeOrDegrade($brief, 'agent', 'generateStyleAnchor')) {
                return;
            }

            $brief->update([
                'style_anchor' => $agent->generateStyleAnchor($brief->prompt),
            ]);
        }

        $brief->refresh();

        if ($brief->shots()->doesntExist()) {
            if (! $budget->chargeOrDegrade($brief, 'agent', 'planShots')) {
                return;
            }

            foreach ($agent->planShots($brief->prompt) as $shotData) {
                $shot = Shot::create([
                    'brief_id' => $brief->id,
                    'sequence' => $shotData['sequence'],
                    'prompt' => $shotData['prompt'],
                    'state' => ShotState::Pending,
                ]);

                ShotUpdated::dispatch($shot);
            }
        }

        $brief->refresh();

        if ($brief->state !== BriefState::Planning) {
            return;
        }

        $brief->transitionTo(BriefState::Keyframes);
        ProcessKeyframesJob::dispatch($brief->id);
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
