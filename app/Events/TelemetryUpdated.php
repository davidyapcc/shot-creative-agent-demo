<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TelemetryUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $telemetry
     */
    public function __construct(public readonly array $telemetry) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('telemetry'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TelemetryUpdated';
    }

    /**
     * Same body as GET /telemetry.
     *
     * @return array{success: bool, data: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'success' => true,
            'data' => $this->telemetry,
        ];
    }
}
