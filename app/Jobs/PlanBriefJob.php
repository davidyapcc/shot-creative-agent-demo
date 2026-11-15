<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\DTOs\ShotPlan;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\PaidCall;
use App\Services\PaidCallClaim;
use App\Services\PaidCallOutcome;
use App\Traits\FailsBriefOnJobFailure;
use App\Traits\RetriesRateLimitedAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PlanBriefJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;
    use RetriesRateLimitedAgent;

    /**
     * Ledger operation for the one paid planning call.
     */
    private const PLAN_OPERATION = 'planShots';

    /**
     * Brief metadata key that marks the plan's claim.
     */
    private const CLAIM_KEY = 'planning_claimed';

    public function __construct(
        public readonly int $briefId,
    ) {}

    public function handle(AgentInterface $agent, PaidCall $paidCall): void
    {
        $brief = Brief::findOrFail($this->briefId);

        if ($brief->state === BriefState::Pending) {
            $brief->transitionTo(BriefState::Planning);
        }

        if ($brief->state !== BriefState::Planning) {
            return;
        }

        if ($brief->shots()->doesntExist() && ! $this->writePlan($brief, $agent, $paidCall)) {
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
     * Pay for one plan and store it. False when the brief has no plan to move
     * on with: the budget stopped it, another worker is still planning, or the
     * brief left Planning during the call.
     */
    private function writePlan(Brief $brief, AgentInterface $agent, PaidCall $paidCall): bool
    {
        $result = $paidCall->forBrief(
            $brief,
            BriefState::Planning,
            'agent',
            self::PLAN_OPERATION,
            call: fn (): ShotPlan => $agent->planShots($brief->prompt),
            apply: fn (Brief $locked, ShotPlan $plan): array => $this->savePlan($locked, $plan),
            claim: $this->claim(),
        );

        if ($result->outcome === PaidCallOutcome::ClaimLost) {
            return $brief->shots()->exists();
        }

        if (! $result->wasApplied()) {
            return false;
        }

        foreach ($result->value as $shot) {
            ShotUpdated::dispatch($shot);
        }

        return true;
    }

    /**
     * The claim is the planning_claimed marker in the brief's metadata, set until the plan is stored or given back.
     */
    private function claim(): PaidCallClaim
    {
        return new PaidCallClaim(
            take: function (Brief $locked): bool {
                if ($locked->shots()->exists() || ($locked->metadata[self::CLAIM_KEY] ?? false) === true) {
                    return false;
                }

                $locked->update(['metadata' => [...$locked->metadata ?? [], self::CLAIM_KEY => true]]);

                return true;
            },
            release: function (Brief $locked): void {
                $locked->update(['metadata' => $this->withoutClaim($locked)]);
            },
        );
    }

    /**
     * Store the anchor and shots together and clear the claim.
     *
     * @return list<Shot>
     */
    private function savePlan(Brief $locked, ShotPlan $plan): array
    {
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
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutClaim(Brief $brief): array
    {
        return array_diff_key($brief->metadata ?? [], [self::CLAIM_KEY => true]);
    }
}
