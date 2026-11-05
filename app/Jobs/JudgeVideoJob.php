<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\JudgeInterface;
use App\Enums\JudgementStage;
use App\Enums\ShotState;
use App\Events\JudgeVerdictRecorded;
use App\Events\ShotUpdated;
use App\Exceptions\ProviderRateLimitedException;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\Budget;
use App\Services\RateLimitHitCounter;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use App\Traits\RetriesRateLimitedJudge;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class JudgeVideoJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;
    use RetriesRateLimitedJudge;

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
    ) {
        $this->onQueue('judge');
    }

    public function handle(
        JudgeInterface $judge,
        ShotStepRetry $retry,
        Budget $budget,
        ?RateLimitHitCounter $rateLimits = null,
    ): void {
        $rateLimits ??= new RateLimitHitCounter;

        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        if (! $budget->chargeShotStep($shot, 'judge', 'judgeVideo')) {
            return;
        }

        try {
            $verdict = $judge->judgeVideo(
                (string) $shot->video_path,
                $shot->prompt,
                $shot->brief?->style_anchor,
            );
        } catch (ProviderRateLimitedException $exception) {
            $this->releaseRateLimitedJudge($shot, $budget, $rateLimits, 'judgeVideo');

            throw $exception;
        }

        Judgement::create($verdict->toModelAttributes($shot->id, JudgementStage::Video));

        event(JudgeVerdictRecorded::fromVerdict(
            $this->briefId,
            $this->shotId,
            JudgementStage::Video,
            $verdict,
        ));

        if ($verdict->isPass()) {
            if ($shot->advanceUnlessStopped(ShotState::Done)) {
                ShotUpdated::dispatch($shot);
            }
        } else {
            ShotUpdated::dispatch($shot->refresh());
            $retry->afterFailure($shot, $verdict, JudgementStage::Video, $this->batch());
        }
    }
}
