<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\JudgementResult;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Exceptions\ProviderRateLimitedException;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\PlanBriefJob;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ShotStepRetry
{
    public const MAX_RETRIES = 2;

    public function __construct(
        private readonly AgentInterface $agent,
        private readonly Budget $budget,
        private readonly ShotVideoSubmitter $submitter,
        private readonly VideoProviderInterface $videos,
        private readonly RateLimitHitCounter $rateLimits,
    ) {}

    /**
     * Continue a rewrite the provider refused, without judging the shot again.
     */
    public function resumePendingRewrite(Shot $shot, JudgementStage $stage, ?Batch $batch = null): bool
    {
        $pending = $shot->metadata['pending_agent_rewrite'] ?? null;

        if (! is_array($pending) || ($pending['stage'] ?? null) !== $stage->value) {
            return false;
        }

        $judgement = Judgement::query()->find($pending['judgement_id'] ?? null);

        if ($judgement === null) {
            return false;
        }

        $this->afterFailure($shot, new JudgementResult(
            scores: $judgement->scores,
            hardFailTags: $judgement->hard_fail_tags,
            reason: $judgement->reason,
            verdict: JudgementVerdict::from($judgement->verdict),
        ), $stage, $batch);

        return true;
    }

    public function afterFailure(
        Shot $shot,
        JudgementResult $verdict,
        JudgementStage $stage,
        ?Batch $batch = null,
    ): void {
        $shot->loadMissing('brief');

        $step = $stage === JudgementStage::Image ? 'image' : 'video';
        // Snapshot before the charge refreshes the shot; the attempt log is built on it.
        $metadata = $shot->metadata ?? [];

        if ($this->hasUsedAllRetries($shot, $metadata, $step)) {
            return;
        }

        if ($this->agentIsRateLimited()) {
            $this->rememberPendingRewrite($shot, $stage);

            throw new ProviderRateLimitedException('Agent rate limit reached.');
        }

        if (! $this->chargeRewrite($shot)) {
            return;
        }

        RateLimiter::hit(PlanBriefJob::rateLimitKey(), 60);

        try {
            $saved = $this->rewriteAndLogAttempt($shot, $verdict, $metadata, $step);
        } catch (ProviderRateLimitedException $exception) {
            $this->budget->refundShotStep($shot, 'agent', 'rewritePrompt');
            $this->rememberPendingRewrite($shot, $stage);
            $this->rateLimits->increment('agent');

            throw $exception;
        }

        if (! $saved) {
            return;
        }

        $this->retryStep($shot, $stage, $batch);
    }

    /**
     * Fail the shot once its retries for this step are spent.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function hasUsedAllRetries(Shot $shot, array $metadata, string $step): bool
    {
        $used = (int) ($metadata['step_attempts'][$step] ?? 0);

        if ($used <= self::MAX_RETRIES) {
            return false;
        }

        if ($shot->state->canTransitionTo(ShotState::Failed)) {
            $shot->transitionTo(ShotState::Failed);
        }

        return true;
    }

    /**
     * Meter the rewrite call. False when the budget stop fired or failed the shot.
     */
    private function chargeRewrite(Shot $shot): bool
    {
        return $this->budget->chargeShotStep($shot, 'agent', 'rewritePrompt');
    }

    private function agentIsRateLimited(): bool
    {
        return RateLimiter::tooManyAttempts(
            PlanBriefJob::rateLimitKey(),
            (int) config('creative.agent.requests_per_minute', 10),
        );
    }

    private function rememberPendingRewrite(Shot $shot, JudgementStage $stage): void
    {
        $metadata = $shot->metadata ?? [];
        $metadata['pending_agent_rewrite'] = [
            'stage' => $stage->value,
            'judgement_id' => Judgement::query()
                ->where('shot_id', $shot->id)
                ->where('stage', $stage->value)
                ->latest('id')
                ->value('id'),
        ];

        $shot->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function rewriteAndLogAttempt(Shot $shot, JudgementResult $verdict, array $metadata, string $step): bool
    {
        $log = $metadata['prompt_attempts'][$step] ?? [];

        // The first rewrite overwrites shots.prompt, so keep the planned prompt
        // here; every later rewrite must start from the scene as planned.
        $originalPrompt = (string) ($metadata['original_prompt'] ?? $shot->prompt);
        $metadata['original_prompt'] = $originalPrompt;

        $rewritten = $this->rewrite($shot, $verdict, $originalPrompt, $log);

        $log[] = [
            'prompt' => $shot->prompt,
            'reason' => $verdict->reason,
        ];
        $metadata['prompt_attempts'][$step] = $log;
        unset($metadata['pending_agent_rewrite']);

        $saved = DB::transaction(function () use ($shot, $rewritten, $metadata): bool {
            $locked = Shot::query()->whereKey($shot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state === ShotState::Failed) {
                return false;
            }

            $locked->update([
                'prompt' => $rewritten,
                'metadata' => $metadata,
            ]);

            return true;
        });

        if ($saved) {
            $shot->refresh();
        }

        return $saved;
    }

    /**
     * @param  list<array<string, mixed>>  $previousAttempts
     */
    private function rewrite(Shot $shot, JudgementResult $verdict, string $originalPrompt, array $previousAttempts): string
    {
        return $this->agent->rewritePrompt([
            'brief' => (string) $shot->brief?->prompt,
            'original_prompt' => $originalPrompt,
            'failed_scores' => $verdict->scores,
            'reason' => $verdict->reason,
            'previous_attempts' => $previousAttempts,
        ]);
    }

    private function retryStep(Shot $shot, JudgementStage $stage, ?Batch $batch): void
    {
        if ($stage === JudgementStage::Image) {
            $this->dispatch([
                new GenerateKeyframeJob($shot->id, $shot->brief_id),
                new JudgeKeyframeJob($shot->id, $shot->brief_id),
            ], $batch);

            return;
        }

        $this->resubmitVideo($shot, $batch);
    }

    private function resubmitVideo(Shot $shot, ?Batch $batch): void
    {
        $poll = $this->submitter->submit($shot, $this->videos);

        if ($poll === null) {
            return;
        }

        if ($batch !== null) {
            $batch->add([$poll]);

            return;
        }

        dispatch($poll);
    }

    /**
     * @param  list<object>  $jobs
     */
    private function dispatch(array $jobs, ?Batch $batch): void
    {
        if ($batch !== null) {
            $batch->add([$jobs]);

            return;
        }

        Bus::chain($jobs)->dispatch();
    }
}
