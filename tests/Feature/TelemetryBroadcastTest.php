<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Events\TelemetryUpdated;
use App\Models\Brief;
use App\Services\Telemetry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class TelemetryBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_finishing_a_brief_broadcasts_the_telemetry_snapshot_on_the_public_channel(): void
    {
        $brief = Brief::create([
            'prompt' => 'telemetry brief',
            'state' => BriefState::Muxing,
        ]);
        $brief->created_at = Carbon::parse('2026-11-06 00:00:00');
        $brief->save();

        Carbon::setTestNow('2026-11-06 00:00:30');
        Event::fake([TelemetryUpdated::class]);

        $brief->transitionTo(BriefState::Done);

        Event::assertDispatched(TelemetryUpdated::class, function (TelemetryUpdated $event): bool {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();
            $data = $payload['data'];

            return $event instanceof ShouldBroadcastNow
                && count($channels) === 1
                && $channels[0] instanceof Channel
                && $channels[0]->name === 'telemetry'
                && $event->broadcastAs() === 'TelemetryUpdated'
                && $payload['success'] === true
                && $data['pass_rate'] === null
                && $data['top_failure_tags'] === []
                && abs((float) $data['duration_p50_seconds'] - 30) < 0.001
                && abs((float) $data['duration_p95_seconds'] - 30) < 0.001;
        });

        Carbon::setTestNow();
    }

    public function test_a_pipeline_step_does_not_broadcast_telemetry(): void
    {
        Event::fake([TelemetryUpdated::class]);

        $brief = Brief::create([
            'prompt' => 'telemetry brief',
            'state' => BriefState::Pending,
        ]);

        $brief->transitionTo(BriefState::Planning);

        Event::assertNotDispatched(TelemetryUpdated::class);
    }

    public function test_a_telemetry_failure_does_not_stop_the_brief_from_finishing(): void
    {
        $this->mock(Telemetry::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andThrow(new RuntimeException('redis down'));
        });

        $brief = Brief::create([
            'prompt' => 'telemetry brief',
            'state' => BriefState::Muxing,
        ]);

        $brief->transitionTo(BriefState::Done);

        $brief->refresh();
        $this->assertSame(BriefState::Done, $brief->state);
        $this->assertNotNull($brief->completed_at);
    }
}
