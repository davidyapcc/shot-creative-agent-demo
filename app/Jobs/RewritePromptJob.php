<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AgentInterface;
use App\Enums\JudgementStage;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\PaidCall;
use App\Services\PaidCallClaim;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use App\Traits\RetriesRateLimitedAgent;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
     * The judgement whose rewrite is claimed or done. A second copy of this
     * job for the same judgement finds it set and does not pay again.
     */
    private const CLAIM_KEY = 'rewrite_judgement_id';

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
        public readonly int $judgementId,
        public readonly JudgementStage $stage,
    ) {}

    public function handle(AgentInterface $agent, PaidCall $paidCall, ShotStepRetry $retry): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);
        $judgement = Judgement::findOrFail($this->judgementId);

        $result = $paidCall->forShot(
            $shot,
            'agent',
            self::OPERATION,
            call: fn (): string => $agent->rewritePrompt($this->context($shot, $judgement)),
            apply: fn (Shot $locked, string $rewritten) => $this->save($locked, $judgement, $rewritten),
            claim: $this->claim(),
        );

        if ($result->wasApplied()) {
            $retry->retryStep($shot, $this->stage, $this->batch());
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

    /**
     * The claim is this job's judgement id in the shot's metadata.
     */
    private function claim(): PaidCallClaim
    {
        return new PaidCallClaim(
            take: function (Shot $locked): bool {
                if (($locked->metadata[self::CLAIM_KEY] ?? null) === $this->judgementId) {
                    return false;
                }

                $locked->update(['metadata' => [...$locked->metadata ?? [], self::CLAIM_KEY => $this->judgementId]]);

                return true;
            },
            release: function (Shot $locked): void {
                $locked->update(['metadata' => array_diff_key($locked->metadata ?? [], [self::CLAIM_KEY => true])]);
            },
        );
    }

    /**
     * Store the rewrite and log the prompt it replaces.
     */
    private function save(Shot $locked, Judgement $judgement, string $rewritten): void
    {
        $metadata = $locked->metadata ?? [];
        $metadata['original_prompt'] = $this->originalPrompt($locked);
        $metadata['prompt_attempts'][$this->stage->value][] = [
            'prompt' => $locked->prompt,
            'reason' => $judgement->reason,
        ];

        $locked->update(['prompt' => $rewritten, 'metadata' => $metadata]);
    }
}
