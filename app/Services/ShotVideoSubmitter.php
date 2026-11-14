<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VideoProviderInterface;
use App\Enums\ShotState;
use App\Events\ShotUpdated;
use App\Events\VideoProgressUpdated;
use App\Jobs\PollVideoRenderJob;
use App\Models\Shot;

class ShotVideoSubmitter
{
    private const INITIAL_PROGRESS = 0;

    public function __construct(
        private readonly PaidCall $paidCall,
    ) {}

    /**
     * Pay for one render submit. Returns the poll for it, or null when nothing
     * was submitted or the submit came back late.
     */
    public function submit(Shot $shot, VideoProviderInterface $videos): ?PollVideoRenderJob
    {
        $shot->loadMissing('brief');

        if ($shot->brief === null) {
            return null;
        }

        $result = $this->paidCall->forShot(
            $shot,
            'video',
            'submit',
            call: fn (): string => $videos->submit($shot->prompt, (string) $shot->keyframe_path),
            apply: function (Shot $locked, string $renderId): string {
                if ($locked->state->canTransitionTo(ShotState::GeneratingVideo)) {
                    $locked->transitionTo(ShotState::GeneratingVideo);
                }

                $locked->recordStepAttempt('video', ['render_id' => $renderId]);

                return $renderId;
            },
            // A failure that kept its charge is a generation (shots.attempts counts every one).
            onFailure: fn (Shot $locked) => $locked->recordStepAttempt('video'),
        );

        if (! $result->wasApplied()) {
            return null;
        }

        VideoProgressUpdated::dispatch($shot->brief_id, $shot->id, self::INITIAL_PROGRESS);
        ShotUpdated::dispatch($shot);

        return new PollVideoRenderJob($shot->id, $result->value, $shot->brief_id);
    }
}
