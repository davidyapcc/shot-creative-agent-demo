<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VideoProviderInterface;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Jobs\GenerateKeyframeJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\RewritePromptJob;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class ShotStepRetry
{
    public const MAX_RETRIES = 2;

    public function __construct(
        private readonly ShotVideoSubmitter $submitter,
        private readonly VideoProviderInterface $videos,
    ) {}

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
        $used = (int) ($shot->metadata['step_attempts'][$stage->value] ?? 0);

        if ($used <= self::MAX_RETRIES) {
            return false;
        }

        if ($shot->state->canTransitionTo(ShotState::Failed)) {
            $shot->transitionTo(ShotState::Failed);
        }

        return true;
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
