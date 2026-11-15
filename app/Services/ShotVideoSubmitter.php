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

    /**
     * The video generation count a submit is claimed for. A second copy of the
     * same submit finds it set, or finds the count moved on, and does not pay.
     */
    private const CLAIM_KEY = 'video_submit_claim';

    public function __construct(
        private readonly PaidCall $paidCall,
    ) {}

    /**
     * Pay for one render submit. Returns the poll for it, or null when nothing
     * was submitted or the submit came back late.
     *
     * @param  int  $priorVideoAttempts  the shot's video generations before this submit; it names the submit for its claim
     */
    public function submit(Shot $shot, VideoProviderInterface $videos, int $priorVideoAttempts): ?PollVideoRenderJob
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
            claim: $this->claim($priorVideoAttempts),
            onFailure: fn (Shot $locked) => $locked->recordStepAttempt('video'),
        );

        if (! $result->wasApplied()) {
            return null;
        }

        VideoProgressUpdated::dispatch($shot->brief_id, $shot->id, self::INITIAL_PROGRESS);
        ShotUpdated::dispatch($shot);

        return new PollVideoRenderJob($shot->id, $result->value, $shot->brief_id);
    }

    /**
     * Only a shot that can still render, and has made no video generation
     * since this submit was queued, takes the claim.
     */
    private function claim(int $priorVideoAttempts): PaidCallClaim
    {
        return new PaidCallClaim(
            take: function (Shot $locked) use ($priorVideoAttempts): bool {
                $isClaimed = ($locked->metadata[self::CLAIM_KEY] ?? null) === $priorVideoAttempts;

                if ($isClaimed
                    || $locked->stepAttempts('video') !== $priorVideoAttempts
                    || ! $locked->state->canTransitionTo(ShotState::GeneratingVideo)) {
                    return false;
                }

                $locked->update(['metadata' => [...$locked->metadata ?? [], self::CLAIM_KEY => $priorVideoAttempts]]);

                return true;
            },
            release: function (Shot $locked): void {
                $locked->update(['metadata' => array_diff_key($locked->metadata ?? [], [self::CLAIM_KEY => true])]);
            },
        );
    }
}
