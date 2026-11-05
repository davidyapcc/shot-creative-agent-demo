<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use Illuminate\Bus\Batch;

class AdvanceBriefToRenderingCallback
{
    public function __construct(
        public readonly int $briefId,
    ) {}

    public function __invoke(Batch $batch): void
    {
        if ($batch->cancelled()) {
            return;
        }

        $brief = Brief::find($this->briefId);

        if ($brief !== null && ! $brief->isBudgetExhausted() && $brief->state === BriefState::Keyframes) {
            $brief->transitionTo(BriefState::Rendering);
            RenderShotsJob::dispatch($this->briefId);
        }
    }
}
