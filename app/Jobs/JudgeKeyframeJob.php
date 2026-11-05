<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\JudgeInterface;
use App\Enums\JudgementStage;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class JudgeKeyframeJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
    ) {
        $this->onQueue('judge');
    }

    public function handle(JudgeInterface $judge, ShotStepRetry $retry, Budget $budget): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        if (! $budget->chargeShotStep($shot, 'judge', 'judgeImage')) {
            return;
        }

        $verdict = $judge->judgeImage(
            (string) $shot->keyframe_path,
            $shot->prompt,
            $shot->brief?->style_anchor,
        );

        Judgement::create($verdict->toModelAttributes($shot->id, JudgementStage::Image));

        event(JudgeVerdictRecorded::fromVerdict(
            $this->briefId,
            $this->shotId,
            JudgementStage::Image,
            $verdict,
        ));

        ShotUpdated::dispatch($shot->refresh());

        if ($verdict->isFail()) {
            $retry->afterFailure($shot, $verdict, JudgementStage::Image, $this->batch());
        }
    }
}
