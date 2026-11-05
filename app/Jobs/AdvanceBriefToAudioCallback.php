<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Models\Brief;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;

class AdvanceBriefToAudioCallback
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

        if ($brief === null || $brief->isBudgetExhausted() || $brief->state !== BriefState::Rendering) {
            return;
        }

        if (! $brief->hasPassingClip()) {
            // Every shot used up its video retries; audio would be a paid call for
            // a brief that has nothing to mux.
            Log::warning('No shot passed its video judge; failing the brief before audio.', [
                'brief_id' => $brief->id,
            ]);
            $brief->failWithInFlightShots();

            return;
        }

        $brief->transitionTo(BriefState::Audio);
        GenerateAudioJob::dispatch($this->briefId);
    }
}
