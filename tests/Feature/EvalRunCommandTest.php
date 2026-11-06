<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EvalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvalRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_golden_run_scores_the_planted_disagreements(): void
    {
        Storage::fake('s3');
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;

        try {
            $this->artisan('eval:run', [
                '--judge' => 'replay',
                '--set' => 'golden',
            ])->assertSuccessful();

            $run = EvalRun::query()->sole();

            $this->assertSame('complete', $run->status);
            $this->assertSame('replay', $run->judge_driver);
            $this->assertSame('replay', $run->judge_model);
            $this->assertSame('golden', $run->dataset);
            $this->assertSame(64, strlen((string) $run->rubric_version));
            $this->assertCount(6, $run->results);
            $this->assertCount(6, $run->ledger);
            $this->assertEqualsWithDelta(2 / 3, $run->metrics['precision'], 0.0001);
            $this->assertEqualsWithDelta(2 / 3, $run->metrics['recall'], 0.0001);
            $this->assertEqualsWithDelta(0.5, $run->metrics['judge_pass_rate'], 0.0001);
            $this->assertSame(6, $run->metrics['cost_units']);
            $this->assertSame('n/a', $run->metrics['per_tag_recall']['anatomy']);
            $this->assertSame('n/a', $run->metrics['per_tag_recall']['scene_mismatch']);
            $this->assertEqualsWithDelta(0, $run->metrics['per_tag_recall']['object_warp'], 0.0001);
            $this->assertEqualsWithDelta(1, $run->metrics['per_tag_recall']['temporal_flicker'], 0.0001);
            $this->assertEqualsWithDelta(1, $run->metrics['per_tag_recall']['text_artifact'], 0.0001);

            $warp = collect($run->results)->firstWhere('id', 'warp-car');
            $this->assertSame('pass', $warp['verdict']);
            $this->assertSame([], $warp['tags']);
            $this->assertArrayHasKey('prompt_adherence', $warp['scores']);
            $this->assertSame('fail', $warp['human']['verdict']);
            $this->assertSame(['object_warp'], $warp['human']['tags']);
            $this->assertNotSame('', $warp['reason']);

            $sign = collect($run->results)->firstWhere('id', 'steady-sign');
            $this->assertSame('fail', $sign['verdict']);
            $this->assertSame(['anatomy'], $sign['tags']);
            $this->assertSame('pass', $sign['human']['verdict']);

            foreach ([
                'evals/golden/steady-street.mp4',
                'evals/golden/steady-hands.mp4',
                'evals/golden/steady-sign.mp4',
                'evals/golden/flicker-neon.mp4',
                'evals/golden/warp-car.mp4',
                'evals/golden/text-marquee.mp4',
            ] as $path) {
                Storage::disk('s3')->assertExists($path);
            }

            $written = file_get_contents($report);
            $this->assertIsString($written);
            $this->assertStringContainsString((string) $run->id, $written);
            $this->assertStringContainsString('replay', $written);
            $this->assertStringContainsString((string) $run->rubric_version, $written);
            $this->assertStringContainsString('steady-sign', $written);
            $this->assertStringContainsString('warp-car', $written);
            $this->assertStringContainsString('n/a', $written);

            $fixture = file_get_contents(base_path('fixtures/replay/eval-judge.json'));
            $this->assertIsString($fixture);
            $this->assertStringContainsString('"placeholder": true', $fixture);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_short_budget_judges_that_many_clips_and_leaves_the_report(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, 'SENTINEL');

        try {
            $this->artisan('eval:run', [
                '--judge' => 'replay',
                '--set' => 'golden',
                '--budget' => '3',
            ])->assertSuccessful();

            $run = EvalRun::query()->sole();

            $this->assertSame('partial', $run->status);
            $this->assertSame(
                ['steady-street', 'steady-hands', 'steady-sign'],
                array_column($run->results, 'id'),
            );
            $this->assertCount(3, $run->ledger);
            $this->assertSame(3, $run->metrics['cost_units']);
            $this->assertSame('SENTINEL', file_get_contents($report));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_throwing_judge_is_recorded_as_an_error_and_left_out_of_metrics(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        config([
            'services.gemini.key' => 'gemini-key',
            'creative.judge.video_fps' => 6,
            'creative.judge.requests_per_minute' => 100,
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('nope', 500),
        ]);

        $this->artisan('eval:run', [
            '--judge' => 'gemini',
            '--set' => 'golden',
        ])->assertSuccessful();

        $run = EvalRun::query()->sole();

        $this->assertSame('partial', $run->status);
        $this->assertCount(6, $run->results);

        foreach ($run->results as $result) {
            $this->assertSame('error', $result['verdict']);
            $this->assertSame('Gemini judge returned HTTP 500.', $result['error']);
        }

        $this->assertSame('n/a', $run->metrics['precision']);
        $this->assertSame('n/a', $run->metrics['recall']);
        $this->assertSame('n/a', $run->metrics['judge_pass_rate']);
        $this->assertSame(6, $run->metrics['cost_units']);
        Http::assertSentCount(6);
    }

    public function test_a_rate_limit_refunds_the_clip_and_errors_after_three_refusals(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        config([
            'services.gemini.key' => 'gemini-key',
            'creative.judge.video_fps' => 6,
            'creative.judge.requests_per_minute' => 100,
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('slow', 429),
        ]);

        $this->artisan('eval:run', [
            '--judge' => 'gemini',
            '--set' => 'golden',
        ])->assertSuccessful();

        $run = EvalRun::query()->sole();

        $this->assertSame('partial', $run->status);
        $this->assertSame([], $run->ledger);
        $this->assertSame(0, $run->metrics['cost_units']);

        foreach ($run->results as $result) {
            $this->assertSame('error', $result['verdict']);
            $this->assertSame('Gemini judge returned HTTP 429.', $result['error']);
        }

        Http::assertSentCount(18);
    }

    public function test_judge_defaults_to_the_configured_provider(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        config(['creative.judge.provider' => 'replay']);
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;

        try {
            $this->artisan('eval:run', ['--set' => 'golden'])->assertSuccessful();

            $this->assertSame('replay', EvalRun::query()->sole()->judge_driver);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_unknown_tag_in_the_golden_set_exits_naming_the_clip(): void
    {
        $this->assertGoldenSetRejected(
            [[
                'id' => 'flicker-neon',
                'path' => 'evals/golden/flicker-neon.mp4',
                'prompt' => 'A neon sign flickers.',
                'verdict' => 'fail',
                'tags' => ['melted'],
            ]],
            'flicker-neon',
        );
    }

    public function test_unknown_verdict_in_the_golden_set_exits_naming_the_clip(): void
    {
        $this->assertGoldenSetRejected(
            [[
                'id' => 'warp-car',
                'path' => 'evals/golden/warp-car.mp4',
                'prompt' => 'A car drives past.',
                'verdict' => 'maybe',
                'tags' => [],
            ]],
            'warp-car',
        );
    }

    public function test_diff_prints_both_configs_metric_deltas_and_verdict_changes(): void
    {
        $first = $this->storedRun('replay', 'replay', 'complete', [
            'precision' => 0.5,
            'recall' => 0.5,
            'judge_pass_rate' => 0.5,
            'cost_units' => 6,
            'per_tag_recall' => [
                'anatomy' => 'n/a',
                'object_warp' => 0,
            ],
        ], [
            ['id' => 'warp-car', 'verdict' => 'pass'],
            ['id' => 'steady-sign', 'verdict' => 'fail'],
            ['id' => 'flicker-neon', 'verdict' => 'fail'],
        ], str_repeat('a', 64));

        $second = $this->storedRun('gemini', 'gemini-3.5-flash-lite', 'partial', [
            'precision' => 1,
            'recall' => 0.5,
            'judge_pass_rate' => 1,
            'cost_units' => 3,
            'per_tag_recall' => [
                'anatomy' => 1,
                'object_warp' => 0,
            ],
        ], [
            ['id' => 'warp-car', 'verdict' => 'fail'],
            ['id' => 'steady-sign', 'verdict' => 'fail'],
        ], str_repeat('b', 64));

        $this->artisan('eval:diff', ['runA' => $first->id, 'runB' => $second->id])
            ->expectsOutputToContain('replay  replay  '.str_repeat('a', 64).'  golden  complete')
            ->expectsOutputToContain('gemini  gemini-3.5-flash-lite  '.str_repeat('b', 64).'  golden  partial')
            ->expectsOutputToContain('precision  0.500 -> 1.000  (+0.500)')
            ->expectsOutputToContain('recall  0.500 -> 0.500  (+0.000)')
            ->expectsOutputToContain('cost_units  6.000 -> 3.000  (-3.000)')
            ->expectsOutputToContain('anatomy  n/a -> 1.000')
            ->expectsOutputToContain('warp-car  pass -> fail')
            ->expectsOutputToContain('flicker-neon  fail -> not judged')
            ->doesntExpectOutputToContain('steady-sign')
            ->assertSuccessful();
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     */
    private function assertGoldenSetRejected(array $clips, string $clipId): void
    {
        $path = base_path('evals/golden.json');
        $original = file_get_contents($path);
        file_put_contents($path, json_encode($clips));

        try {
            $this->artisan('eval:run', [
                '--judge' => 'replay',
                '--set' => 'golden',
            ])->expectsOutputToContain($clipId)->assertFailed();

            $this->assertSame(0, EvalRun::query()->count());
        } finally {
            file_put_contents($path, $original);
        }
    }

    private function seedClips(): void
    {
        $clips = json_decode((string) file_get_contents(base_path('evals/golden.json')), true);

        foreach ($clips as $clip) {
            Storage::disk('s3')->put($clip['path'], 'clip-bytes');
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<array<string, mixed>>  $results
     */
    private function storedRun(
        string $driver,
        string $model,
        string $status,
        array $metrics,
        array $results,
        string $rubricVersion,
    ): EvalRun {
        return EvalRun::query()->create([
            'judge_driver' => $driver,
            'judge_model' => $model,
            'rubric_version' => $rubricVersion,
            'dataset' => 'golden',
            'status' => $status,
            'metrics' => $metrics,
            'results' => $results,
            'ledger' => [],
        ]);
    }

    private function restoreReport(string $report, string|false|null $previous): void
    {
        if ($previous === null || $previous === false) {
            if (is_file($report)) {
                unlink($report);
            }

            return;
        }

        file_put_contents($report, $previous);
    }
}
