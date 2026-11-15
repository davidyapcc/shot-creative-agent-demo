<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\JudgementStage;
use App\Models\Shot;
use App\Services\PaidCall;
use App\Services\ShotStepRetry;
use App\Traits\FailsBriefOnJobFailure;
use App\Traits\RetriesRateLimitedJudge;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class JudgeKeyframeJob implements ShouldQueue
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

    public function handle(JudgeInterface $judge, ShotStepRetry $retry, PaidCall $paidCall): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        $this->judgeShot(
            $shot,
            JudgementStage::Image,
            'judgeImage',
            fn (): JudgementResult => $judge->judgeImage(
                (string) $shot->keyframe_path,
                $shot->prompt,
                $shot->brief?->style_anchor,
            ),
            $paidCall,
            $retry,
        );
    }
}
