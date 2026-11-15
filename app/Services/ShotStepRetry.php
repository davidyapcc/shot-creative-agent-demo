<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\RewritePromptJob;
use App\Jobs\SubmitVideoRenderJob;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class ShotStepRetry
{
    public const MAX_RETRIES = 2;

    /**
     * Queue the rewrite for a failed judgement, unless the step's retries are
     * spent. The agent call itself runs in RewritePromptJob.
     */
    public function afterFailure(Shot $shot, Judgement $judgement, JudgementStage $stage, ?Batch $batch = null): void
    {
        if ($this->hasUsedAllRetries($shot, $stage)) {
            return;
        }

        $this->dispatch(new RewritePromptJob($shot->id, $shot->brief_id, $judgement->id, $stage), $batch);
    }

    /**
     * Run the step again on the rewritten prompt.
     */
    public function retryStep(Shot $shot, JudgementStage $stage, ?Batch $batch): void
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

    /**
     * Fail the shot once its retries for this step are spent.
     */
    private function hasUsedAllRetries(Shot $shot, JudgementStage $stage): bool
    {
        $used = $shot->stepAttempts($stage->value);

        if ($used <= self::MAX_RETRIES) {
            return false;
        }

        if ($shot->state->canTransitionTo(ShotState::Failed)) {
            $shot->transitionTo(ShotState::Failed);
        }

        return true;
    }

    /**
     * The resubmit runs in its own job, so a 429 on it retries only the submit
     * and never this rewrite, whose claim is already spent.
     */
    private function resubmitVideo(Shot $shot, ?Batch $batch): void
    {
        $this->dispatch(SubmitVideoRenderJob::forShot($shot), $batch);
    }

    /**
     * Add one job, or a chain given as a list, to the brief's batch.
     *
     * @param  object|list<object>  $jobs
     */
    private function dispatch(object|array $jobs, ?Batch $batch): void
    {
        if ($batch !== null) {
            $batch->add([$jobs]);

            return;
        }

        is_array($jobs) ? Bus::chain($jobs)->dispatch() : dispatch($jobs);
    }
}
