<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ShotUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly Shot $shot,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("briefs.{$this->shot->brief_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ShotUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        /** @var Judgement|null $latestJudgement */
        $latestJudgement = $this->shot->relationLoaded('judgements')
          ? $this->shot->judgements->last()
          : $this->shot->judgements()->latest('id')->first();

        return [
            'briefId' => $this->shot->brief_id,
            'shot' => [
                'id' => $this->shot->id,
                'brief_id' => $this->shot->brief_id,
                'sequence' => $this->shot->sequence,
                'prompt' => $this->shot->prompt,
                'state' => $this->shot->state->value,
                'keyframe_path' => $this->shot->keyframe_path,
                'keyframe_url' => $this->shot->keyframe_path ? "/media/{$this->shot->keyframe_path}" : null,
                'video_path' => $this->shot->video_path,
                'video_url' => $this->shot->video_path ? "/media/{$this->shot->video_path}" : null,
                'attempts' => $this->shot->attempts,
                'latest_judgement' => $latestJudgement ? [
                    'id' => $latestJudgement->id,
                    'stage' => $latestJudgement->stage,
                    'verdict' => $latestJudgement->verdict,
                    'hard_fail_tags' => $latestJudgement->hard_fail_tags,
                    'scores' => $latestJudgement->scores,
                    'reason' => $latestJudgement->reason,
                ] : null,
            ],
        ];
    }
}
