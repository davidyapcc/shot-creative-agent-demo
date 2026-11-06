<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\EvalRunStatus;
use App\Models\EvalRun;
use App\Services\JudgeFactory;
use App\Services\MeteredClipJudge;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

            $this->assertSame(EvalRunStatus::Complete, $run->status);
            $this->assertSame('replay', $run->judge_driver);
            $this->assertSame('replay', $run->judge_model);
            $this->assertSame('golden', $run->dataset);
            $this->assertSame(64, strlen((string) $run->rubric_version));
            $this->assertCount(6, $run->results);
            $this->assertCount(6, $run->ledger);
            $this->assertEqualsWithDelta(1, $run->metrics['precision'], 0.0001);
            $this->assertEqualsWithDelta(2 / 3, $run->metrics['recall'], 0.0001);
            $this->assertEqualsWithDelta(2 / 3, $run->metrics['judge_pass_rate'], 0.0001);
            $this->assertSame(6, $run->metrics['cost_units']);
            $this->assertSame('n/a', $run->metrics['per_tag_recall']['anatomy']);
            $this->assertSame('n/a', $run->metrics['per_tag_recall']['scene_mismatch']);
            $this->assertEqualsWithDelta(0, $run->metrics['per_tag_recall']['object_warp'], 0.0001);
            $this->assertEqualsWithDelta(1, $run->metrics['per_tag_recall']['temporal_flicker'], 0.0001);
            $this->assertEqualsWithDelta(0, $run->metrics['per_tag_recall']['text_artifact'], 0.0001);

            $warp = collect($run->results)->firstWhere('id', 'warp-car');
            $this->assertSame('pass', $warp['verdict']);
            $this->assertSame([], $warp['tags']);
            $this->assertArrayHasKey('prompt_adherence', $warp['scores']);
            $this->assertSame('fail', $warp['human']['verdict']);
            $this->assertSame(['object_warp'], $warp['human']['tags']);
            $this->assertNotSame('', $warp['reason']);

            $marquee = collect($run->results)->firstWhere('id', 'text-marquee');
            $this->assertSame('fail', $marquee['verdict']);
            $this->assertSame(['object_warp'], $marquee['tags']);
            $this->assertSame('fail', $marquee['human']['verdict']);
            $this->assertSame(['text_artifact'], $marquee['human']['tags']);

            $sign = collect($run->results)->firstWhere('id', 'steady-sign');
            $this->assertSame('pass', $sign['verdict']);

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
            $this->assertStringContainsString('text-marquee', $written);
            $this->assertStringContainsString('warp-car', $written);
            $this->assertStringNotContainsString('steady-sign', $written);
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

            $this->assertSame(EvalRunStatus::Partial, $run->status);
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
            'creative.judge.gemini_model' => 'gemini-3.5-flash-lite',
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

        $this->assertSame(EvalRunStatus::Partial, $run->status);
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

    public function test_a_rate_limit_refunds_waits_and_errors_after_three_refusals(): void
    {
        Sleep::fake();
        Storage::fake('s3');
        $this->seedClips();
        config([
            'services.gemini.key' => 'gemini-key',
            'creative.judge.gemini_model' => 'gemini-3.5-flash-lite',
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

        $this->assertSame(EvalRunStatus::Partial, $run->status);
        $this->assertSame([], $run->ledger);
        $this->assertSame(0, $run->metrics['cost_units']);

        foreach ($run->results as $result) {
            $this->assertSame('error', $result['verdict']);
            $this->assertSame('Gemini judge returned HTTP 429.', $result['error']);
        }

        Http::assertSentCount(18);
        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === MeteredClipJudge::RATE_LIMIT_BACKOFF_SECONDS,
            12,
        );
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

    public function test_a_missing_fixture_clip_fails_the_command_with_its_path(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        $path = base_path('fixtures/replay/eval-judge.json');
        $original = file_get_contents($path);
        $fixture = json_decode((string) $original, true);
        unset($fixture['clips']['evals/golden/warp-car.mp4']);
        file_put_contents($path, json_encode($fixture));

        try {
            $this->artisan('eval:run', [
                '--judge' => 'replay',
                '--set' => 'golden',
            ])->expectsOutputToContain('evals/golden/warp-car.mp4')->assertFailed();

            $this->assertSame(0, EvalRun::query()->count());
        } finally {
            file_put_contents($path, (string) $original);
        }
    }

    public function test_one_error_clip_is_left_out_of_the_scored_metrics(): void
    {
        Storage::fake('s3');
        $this->seedClips();
        $this->app->instance(JudgeFactory::class, $this->stubJudges(new class implements JudgeInterface
        {
            public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                throw new RuntimeException('Images are not judged in evals.');
            }

            public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                return match ($videoPath) {
                    'evals/golden/warp-car.mp4' => throw new RuntimeException('Judge broke on this clip.'),
                    'evals/golden/flicker-neon.mp4' => new JudgementResult(['temporal_consistency' => 1], ['temporal_flicker']),
                    'evals/golden/text-marquee.mp4' => new JudgementResult(['prompt_adherence' => 1], ['text_artifact']),
                    default => new JudgementResult(['prompt_adherence' => 5]),
                };
            }
        }));

        $this->artisan('eval:run', ['--judge' => 'stub', '--set' => 'golden'])->assertSuccessful();

        $run = EvalRun::query()->sole();
        $warp = collect($run->results)->firstWhere('id', 'warp-car');

        $this->assertSame(EvalRunStatus::Partial, $run->status);
        $this->assertCount(6, $run->results);
        $this->assertSame('error', $warp['verdict']);
        $this->assertSame('Judge broke on this clip.', $warp['error']);
        $this->assertEqualsWithDelta(1, $run->metrics['precision'], 0.0001);
        $this->assertEqualsWithDelta(1, $run->metrics['recall'], 0.0001);
        $this->assertEqualsWithDelta(3 / 5, $run->metrics['judge_pass_rate'], 0.0001);
        $this->assertSame('n/a', $run->metrics['per_tag_recall']['object_warp']);
        $this->assertSame(6, $run->metrics['cost_units']);
    }

    public function test_an_unknown_judge_driver_fails_cleanly_without_a_run(): void
    {
        $this->artisan('eval:run', ['--judge' => 'nope', '--set' => 'golden'])
            ->expectsOutputToContain('Unsupported judge provider [nope].')
            ->assertFailed();

        $this->assertSame(0, EvalRun::query()->count());
    }

    public function test_a_missing_judge_key_fails_cleanly_without_a_run(): void
    {
        config(['services.gemini.key' => null]);

        $this->artisan('eval:run', ['--judge' => 'gemini', '--set' => 'golden'])
            ->expectsOutputToContain('GEMINI_API_KEY is required')
            ->assertFailed();

        $this->assertSame(0, EvalRun::query()->count());
    }

    public function test_a_failed_clip_render_fails_cleanly_without_a_run(): void
    {
        Storage::fake('s3');
        Process::fake(['*' => Process::result(errorOutput: 'ffmpeg exploded', exitCode: 1)]);

        $this->artisan('eval:run', ['--judge' => 'replay', '--set' => 'golden'])
            ->expectsOutputToContain('ffmpeg exploded')
            ->assertFailed();

        $this->assertSame(0, EvalRun::query()->count());
    }

    #[DataProvider('invalidBudgets')]
    public function test_an_invalid_budget_fails_without_a_run(string $budget): void
    {
        $this->artisan('eval:run', ['--judge' => 'replay', '--set' => 'golden', '--budget' => $budget])
            ->expectsOutputToContain('--budget must be a whole number of at least 1')
            ->assertFailed();

        $this->assertSame(0, EvalRun::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidBudgets(): array
    {
        return [
            'not a number' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-2'],
            'fraction' => ['1.5'],
        ];
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
        $first = $this->storedRun('replay', 'replay', EvalRunStatus::Complete, [
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

        $second = $this->storedRun('gemini', 'gemini-3.5-flash-lite', EvalRunStatus::Partial, [
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

    private function stubJudges(JudgeInterface $judge): JudgeFactory
    {
        return new class($judge) extends JudgeFactory
        {
            public function __construct(private readonly JudgeInterface $judge) {}

            public function make(string $driver, ?string $replayFixture = null): JudgeInterface
            {
                return $this->judge;
            }
        };
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
        EvalRunStatus $status,
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
