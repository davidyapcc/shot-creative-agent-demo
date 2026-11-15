<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ShotState;
use App\Models\Brief;
use App\Models\Shot;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class RenderShotsJob implements ShouldQueue
{
    use FailsBriefOnJobFailure;
    use Queueable;

    public function __construct(
        public readonly int $briefId,
    ) {
        $this->onQueue('generate');
    }

    /**
     * Queue one submit per shot that passed its keyframe, in one batch. Each
     * submit adds its poll to the batch, so the batch ends only once every
     * shot has rendered, been judged, or run out of retries.
     */
    public function handle(): void
    {
        $brief = Brief::with('shots')->findOrFail($this->briefId);

        $submits = $brief->shots
            ->filter(fn (Shot $shot): bool => $shot->state->canTransitionTo(ShotState::GeneratingVideo))
            ->map(fn (Shot $shot): SubmitVideoRenderJob => SubmitVideoRenderJob::forShot($shot))
            ->values()
            ->all();

        if ($submits === []) {
            $this->failWhenNoShotCanRender($brief);

            return;
        }

        Bus::batch($submits)
            ->name("render-brief-{$brief->id}")
            ->then(new AdvanceBriefToAudioCallback($brief->id))
            ->catch(new FailBriefOnBatchFailureCallback($brief->id))
            ->dispatch();
    }

    /**
     * Every shot used up its image retries, so no batch will ever advance the
     * brief. A budget stop is left to DegradedCompletion, which owns that path.
     */
    private function failWhenNoShotCanRender(Brief $brief): void
    {
        $brief->refresh();

        if ($brief->isBudgetExhausted()) {
            return;
        }

        Log::warning('No shot passed its keyframe; failing the brief.', [
            'brief_id' => $brief->id,
        ]);

        $brief->failWithInFlightShots();
    }
}
