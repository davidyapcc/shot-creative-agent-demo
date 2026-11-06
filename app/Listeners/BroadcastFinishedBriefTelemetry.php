<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BriefStateChanged;
use App\Events\TelemetryUpdated;
use App\Services\Telemetry;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastFinishedBriefTelemetry
{
    /**
     * Publish a fresh quality snapshot when a brief finishes.
     * A failed read must not undo the terminal state.
     */
    public function handle(BriefStateChanged $event): void
    {
        if (! $event->to->isTerminal()) {
            return;
        }

        try {
            TelemetryUpdated::dispatch(app(Telemetry::class)->snapshot());
        } catch (Throwable $exception) {
            Log::warning('Telemetry snapshot failed after a brief finished.', [
                'brief_id' => $event->briefId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
