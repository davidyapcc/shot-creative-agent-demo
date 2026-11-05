<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use App\Traits\RetriesRateLimitedAgent;
use Closure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite Prompt on Fail: one paid agent call per failed judgement, then the
 * retry of that shot's step.
 */
class RewritePromptJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;
    use RetriesRateLimitedAgent;

    private const OPERATION = 'rewritePrompt';

    /**
     * The judgement whose rewrite is in flight or done. A second copy of this
     * job for the same judgement finds it set and does not pay again.
     */
    private const CLAIM_KEY = 'rewrite_judgement_id';

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
        public readonly int $judgementId,
        public readonly JudgementStage $stage,
    ) {}

    public function handle(
        AgentInterface $agent,
        Budget $budget,
        ShotStepRetry $retry,
        RateLimitHitCounter $rateLimits,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);
        $judgement = Judgement::findOrFail($this->judgementId);

        if (! $budget->chargeShotStep($shot, 'agent', self::OPERATION)) {
            return;
        }

        if (! $this->claim($shot)) {
            $budget->refundShotStep($shot, 'agent', self::OPERATION);

            return;
        }

        try {
            $rewritten = $agent->rewritePrompt($this->context($shot, $judgement));
        } catch (ProviderRateLimitedException $exception) {
            // A refused request spent no quota.
            $this->releaseClaim($shot);
            $budget->refundShotStep($shot, 'agent', self::OPERATION);
            $rateLimits->increment('agent');

            throw $exception;
        }

        if ($this->save($shot, $judgement, $rewritten)) {
            $retry->retryStep($shot->refresh(), $this->stage, $this->batch());
        }
    }

    /**
     * @return array{
     *   brief: string,
     *   original_prompt: string,
     *   failed_scores: array<string, int>,
     *   reason: string|null,
     *   previous_attempts: list<array{prompt: string, reason: string|null}>
     * }
     */
    private function context(Shot $shot, Judgement $judgement): array
    {
        $metadata = $shot->metadata ?? [];

        return [
            'brief' => (string) $shot->brief?->prompt,
            'original_prompt' => $this->originalPrompt($shot),
            'failed_scores' => $judgement->scores,
            'reason' => $judgement->reason,
            'previous_attempts' => $metadata['prompt_attempts'][$this->stage->value] ?? [],
        ];
    }

    /**
     * The first rewrite overwrites shots.prompt, so every rewrite starts from
     * the scene as planned.
     */
    private function originalPrompt(Shot $shot): string
    {
        return (string) ($shot->metadata['original_prompt'] ?? $shot->prompt);
    }

    private function claim(Shot $shot): bool
    {
        return $this->withLockedShot($shot, function (Shot $locked): bool {
            if ($locked->state === ShotState::Failed
                || ($locked->metadata[self::CLAIM_KEY] ?? null) === $this->judgementId) {
                return false;
            }

            $locked->update(['metadata' => [...$locked->metadata ?? [], self::CLAIM_KEY => $this->judgementId]]);

            return true;
        });
    }

    private function releaseClaim(Shot $shot): void
    {
        $this->withLockedShot($shot, function (Shot $locked): void {
            $locked->update(['metadata' => array_diff_key($locked->metadata ?? [], [self::CLAIM_KEY => true])]);
        });
    }

    /**
     * Store the rewrite and log the prompt it replaces. False when the shot was
     * stopped while the call ran; the late rewrite is dropped.
     */
    private function save(Shot $shot, Judgement $judgement, string $rewritten): bool
    {
        return $this->withLockedShot($shot, function (Shot $locked) use ($judgement, $rewritten): bool {
            if ($locked->state === ShotState::Failed) {
                return false;
            }

            $metadata = $locked->metadata ?? [];
            $metadata['original_prompt'] = $this->originalPrompt($locked);
            $metadata['prompt_attempts'][$this->stage->value][] = [
                'prompt' => $locked->prompt,
                'reason' => $judgement->reason,
            ];

            $locked->update(['prompt' => $rewritten, 'metadata' => $metadata]);

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Shot): T  $callback
     * @return T
     */
    private function withLockedShot(Shot $shot, Closure $callback): mixed
    {
        return DB::transaction(function () use ($shot, $callback): mixed {
            $locked = Shot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }
}
