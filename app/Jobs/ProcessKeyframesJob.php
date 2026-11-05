<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class ProcessKeyframesJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;

    public function __construct(
        public readonly int $briefId,
    ) {
        $this->onQueue('generate');
    }

    public function handle(): void
    {
        $brief = Brief::with('shots')->findOrFail($this->briefId);

        $chains = [];

        foreach ($brief->shots as $shot) {
            $chains[] = [
                new GenerateKeyframeJob($shot->id, $brief->id),
                new JudgeKeyframeJob($shot->id, $brief->id),
            ];
        }

        if (empty($chains)) {
            return;
        }

        Bus::batch($chains)
            ->name("keyframes-brief-{$brief->id}")
            ->then(new AdvanceBriefToRenderingCallback($brief->id))
            ->catch(new FailBriefOnBatchFailureCallback($brief->id))
            ->dispatch();
    }
}
