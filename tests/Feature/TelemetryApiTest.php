<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use App\Services\RateLimitHitCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use RuntimeException;
use Tests\TestCase;

class TelemetryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_telemetry_renders_an_empty_snapshot_on_a_fresh_install(): void
    {
        $this->horizonMasters([]);

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'pass_rate' => null,
                    'top_failure_tags' => [],
                    'retries_per_brief' => null,
                    'duration_p50_seconds' => null,
                    'duration_p95_seconds' => null,
                    'cost_units_per_brief' => null,
                    'queue_depth' => 0,
                    'failed_jobs' => 0,
                    'rate_limit_hits' => 0,
                ],
            ]);
    }

    public function test_pass_rate_rounds_to_one_decimal_and_failure_tags_are_ranked_over_the_latest_50_judgements(): void
    {
        $this->horizonMasters([]);
        $shot = $this->shot($this->completedBrief(BriefState::Done, 10), 1);

        $this->judgement($shot, 'fail', ['watermark']);
        foreach (range(1, 47) as $ignored) {
            $this->judgement($shot, 'pass');
        }
        $this->judgement($shot, 'fail', ['anatomy', 'flicker']);
        $this->judgement($shot, 'fail', ['anatomy', 'flicker']);
        $this->judgement($shot, 'fail', ['style']);

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertSame(94, $response->json('data.pass_rate'));
        $this->assertSame([
            ['tag' => 'anatomy', 'count' => 2],
            ['tag' => 'flicker', 'count' => 2],
            ['tag' => 'style', 'count' => 1],
        ], $response->json('data.top_failure_tags'));
    }

    public function test_pass_rate_rounds_a_repeating_percentage_to_one_decimal(): void
    {
        $this->horizonMasters([]);
        $shot = $this->shot($this->completedBrief(BriefState::Done, 10), 1);

        $this->judgement($shot, 'pass');
        $this->judgement($shot, 'pass');
        $this->judgement($shot, 'fail', ['anatomy']);

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertEqualsWithDelta(66.7, (float) $response->json('data.pass_rate'), 0.001);
        $this->assertNotNull($response->json('data.pass_rate'));
    }

    public function test_retries_average_the_latest_50_done_and_degraded_briefs(): void
    {
        $this->horizonMasters([]);

        $withSteps = $this->completedBrief(BriefState::Done, 10);
        $this->shot($withSteps, 1, [
            'attempts' => 4,
            'metadata' => ['step_attempts' => ['image' => 3, 'video' => 1]],
        ]);

        $fromAttempts = $this->completedBrief(BriefState::Done, 10);
        $this->shot($fromAttempts, 1, [
            'attempts' => 5,
            'keyframe_path' => 'keyframes/a.png',
            'video_path' => 'videos/a.mp4',
        ]);

        $degraded = $this->completedBrief(BriefState::Degraded, 10);
        $this->shot($degraded, 1, ['attempts' => 0]);

        $stillRunning = $this->openBrief(BriefState::Rendering);
        $this->shot($stillRunning, 1, [
            'attempts' => 101,
            'metadata' => ['step_attempts' => ['image' => 101]],
        ]);

        $unstamped = Brief::create([
            'prompt' => 'done before completed_at existed',
            'state' => BriefState::Done,
        ]);
        $this->shot($unstamped, 1, [
            'attempts' => 42,
            'metadata' => ['step_attempts' => ['image' => 42]],
        ]);

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertEqualsWithDelta(1.7, (float) $response->json('data.retries_per_brief'), 0.001);
    }

    public function test_duration_percentiles_use_nearest_rank_over_done_and_degraded_briefs(): void
    {
        $this->horizonMasters([]);

        $this->completedBrief(BriefState::Done, 10);
        $this->completedBrief(BriefState::Done, 20);
        $this->completedBrief(BriefState::Degraded, 40);
        $this->completedBrief(BriefState::Done, 80);
        $this->completedBrief(BriefState::Done, 160);
        $this->completedBrief(BriefState::Failed, 5);

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertEqualsWithDelta(40, (float) $response->json('data.duration_p50_seconds'), 0.001);
        $this->assertEqualsWithDelta(160, (float) $response->json('data.duration_p95_seconds'), 0.001);
    }

    public function test_cost_averages_ledger_entries_and_falls_back_to_budget_used(): void
    {
        $this->horizonMasters([]);

        $this->completedBrief(BriefState::Done, 10, [
            'budget_used' => 99,
            'metadata' => [
                'budget_ledger' => [
                    ['provider' => 'judge', 'operation' => 'judgeImage', 'cost' => 2],
                    ['provider' => 'video', 'operation' => 'render', 'cost' => 3],
                ],
            ],
        ]);
        $this->completedBrief(BriefState::Degraded, 10, [
            'budget_used' => 9,
        ]);
        $this->completedBrief(BriefState::Done, 10, [
            'budget_used' => 40,
            'metadata' => ['budget_ledger' => []],
        ]);
        $this->completedBrief(BriefState::Failed, 10, [
            'budget_used' => 100,
        ]);

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertEqualsWithDelta(4.7, (float) $response->json('data.cost_units_per_brief'), 0.001);
    }

    public function test_brief_window_keeps_the_latest_50_completed_briefs_when_timestamps_tie(): void
    {
        $this->horizonMasters([]);

        $completedAt = Carbon::parse('2026-11-06 12:00:00');
        $this->completedBrief(BriefState::Done, 1, ['budget_used' => 100], $completedAt);
        foreach (range(1, 50) as $ignored) {
            $this->completedBrief(BriefState::Done, 30, ['budget_used' => 2], $completedAt);
        }

        $response = $this->getJson('/telemetry');

        $response->assertOk();
        $this->assertEqualsWithDelta(2, (float) $response->json('data.cost_units_per_brief'), 0.001);
        $this->assertEqualsWithDelta(30, (float) $response->json('data.duration_p50_seconds'), 0.001);
        $this->assertEqualsWithDelta(30, (float) $response->json('data.duration_p95_seconds'), 0.001);
    }

    public function test_queue_depth_sums_pipeline_queues_and_failed_jobs_when_horizon_is_down(): void
    {
        $this->horizonMasters([]);
        Queue::fake();
        Queue::pushOn('generate', new PlanBriefJob(1));
        Queue::pushOn('judge', new PlanBriefJob(1));
        Queue::pushOn('judge', new PlanBriefJob(1));
        Queue::pushOn('mail', new PlanBriefJob(1));
        $this->failedJob('generate');
        $this->failedJob('audio');

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertJsonPath('data.queue_depth', 3)
            ->assertJsonPath('data.failed_jobs', 2);
    }

    public function test_queue_metrics_fall_back_when_horizon_cannot_be_reached(): void
    {
        $this->mock(MasterSupervisorRepository::class, function ($mock): void {
            $mock->shouldReceive('all')->andThrow(new RuntimeException('redis down'));
        });
        Queue::fake();
        Queue::pushOn('audio', new PlanBriefJob(1));
        $this->failedJob('audio');

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertJsonPath('data.queue_depth', 1)
            ->assertJsonPath('data.failed_jobs', 1);
    }

    public function test_queue_metrics_read_horizon_when_a_master_is_running(): void
    {
        $this->horizonMasters([(object) ['status' => 'running']]);
        $this->mock(WorkloadRepository::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturn([
                ['name' => 'default', 'length' => 2],
                ['name' => 'generate', 'length' => 3],
                ['name' => 'poll', 'length' => 1],
                ['name' => 'judge', 'length' => 4],
                ['name' => 'audio', 'length' => 5],
                ['name' => 'mail', 'length' => 100],
            ]);
        });
        $this->mock(JobRepository::class, function ($mock): void {
            $mock->shouldReceive('countFailed')->andReturn(7);
        });
        Queue::fake();
        Queue::pushOn('generate', new PlanBriefJob(1));
        $this->failedJob('generate');

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertJsonPath('data.queue_depth', 15)
            ->assertJsonPath('data.failed_jobs', 7);
    }

    public function test_failed_jobs_fall_back_to_the_table_when_horizon_cannot_count_them(): void
    {
        $this->horizonMasters([(object) ['status' => 'running']]);
        $this->mock(WorkloadRepository::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturn([
                ['name' => 'default', 'length' => 2],
            ]);
        });
        $this->mock(JobRepository::class, function ($mock): void {
            $mock->shouldReceive('countFailed')->andThrow(new RuntimeException('redis down'));
        });
        $this->failedJob('judge');
        $this->failedJob('judge');

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertJsonPath('data.queue_depth', 2)
            ->assertJsonPath('data.failed_jobs', 2);
    }

    public function test_rate_limit_hits_are_the_total_across_steps(): void
    {
        $this->horizonMasters([]);
        $counter = app(RateLimitHitCounter::class);
        $counter->increment('judge');
        $counter->increment('judge');
        $counter->increment('audio');

        $response = $this->getJson('/telemetry');

        $response->assertOk()
            ->assertJsonPath('data.rate_limit_hits', 3);
    }

    /**
     * @param  array<int, mixed>  $masters
     */
    private function horizonMasters(array $masters): void
    {
        $this->mock(MasterSupervisorRepository::class, function ($mock) use ($masters): void {
            $mock->shouldReceive('all')->andReturn($masters);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completedBrief(
        BriefState $state,
        int $durationSeconds,
        array $attributes = [],
        ?Carbon $completedAt = null,
    ): Brief {
        $completedAt ??= Carbon::parse('2026-11-06 12:00:00');

        $brief = Brief::create(array_merge([
            'prompt' => 'telemetry brief',
            'state' => $state,
            'completed_at' => $completedAt,
        ], $attributes));

        $brief->created_at = $completedAt->copy()->subSeconds($durationSeconds);
        $brief->save();

        return $brief;
    }

    private function openBrief(BriefState $state): Brief
    {
        return Brief::create([
            'prompt' => 'telemetry brief',
            'state' => $state,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shot(Brief $brief, int $sequence, array $attributes = []): Shot
    {
        return Shot::create(array_merge([
            'brief_id' => $brief->id,
            'sequence' => $sequence,
            'prompt' => "Shot {$sequence}",
            'state' => ShotState::Done,
            'attempts' => 0,
        ], $attributes));
    }

    /**
     * @param  list<string>  $tags
     */
    private function judgement(Shot $shot, string $verdict, array $tags = []): Judgement
    {
        return Judgement::create([
            'shot_id' => $shot->id,
            'stage' => 'image',
            'scores' => ['prompt_adherence' => 4],
            'hard_fail_tags' => $tags,
            'reason' => $verdict === 'fail' ? 'failed' : 'ok',
            'verdict' => $verdict,
        ]);
    }

    private function failedJob(string $queue): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'failed',
            'failed_at' => now(),
        ]);
    }
}
