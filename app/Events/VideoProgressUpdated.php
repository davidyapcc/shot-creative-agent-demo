<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class VideoProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $briefId,
        public readonly int $shotId,
        public readonly int $progress,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("briefs.{$this->briefId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'VideoProgressUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'briefId' => $this->briefId,
            'shotId' => $this->shotId,
            'progress' => $this->progress,
        ];
    }
}
