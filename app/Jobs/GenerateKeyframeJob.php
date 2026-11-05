<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Models\Shot;
use App\Services\Budget;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class GenerateKeyframeJob implements ShouldQueue
{
    use Batchable;
    use FailsBriefOnJobFailure;
    use Queueable;

    public function __construct(
        public readonly int $shotId,
        public readonly int $briefId,
    ) {
        $this->onQueue('generate');
    }

    public function handle(ImageProviderInterface $imageProvider, Budget $budget): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $shot = Shot::with('brief')->findOrFail($this->shotId);

        if (! $budget->chargeShotStep($shot, 'image', 'generateImage')) {
            return;
        }

        $shot->recordStepAttempt('image');

        if ($shot->state->canTransitionTo(ShotState::GeneratingImage)) {
            $shot->transitionTo(ShotState::GeneratingImage);
            ShotUpdated::dispatch($shot);
        }

        $imageBytes = $imageProvider->generateImage($shot->prompt, $shot->brief?->style_anchor);
        $keyframePath = "keyframes/brief_{$shot->brief_id}_shot_{$shot->id}.png";

        Storage::disk('s3')->put($keyframePath, $imageBytes);

        $shot->update([
            'keyframe_path' => $keyframePath,
        ]);

        if (! $shot->advanceUnlessStopped(ShotState::JudgingImage)) {
            return;
        }

        ShotUpdated::dispatch($shot);
    }
}
