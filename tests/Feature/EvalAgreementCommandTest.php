<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EvalRunStatus;
use App\Models\EvalRun;
use App\Services\JudgeAgreementReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\BuildsStoredClipResults;
use Tests\TestCase;

class EvalAgreementCommandTest extends TestCase
{
    use BuildsStoredClipResults;
    use RefreshDatabase;

    private const RUBRIC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_RUBRIC = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SENTINEL = "SENTINEL\n";

    private string $report;

    private string|false|null $previousReport;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->report = base_path('evals/REPORT.md');
        $this->previousReport = is_file($this->report) ? file_get_contents($this->report) : null;

        if (is_file($this->report)) {
            unlink($this->report);
        }
    }

    protected function tearDown(): void
    {
        if (is_string($this->previousReport)) {
            file_put_contents($this->report, $this->previousReport);
        } elseif (is_file($this->report)) {
            unlink($this->report);
        }

        parent::tearDown();
    }

    public function test_two_runs_report_the_agreement_rate_and_the_disagreeing_clip(): void
    {
        $ledger = [
            ['clip_id' => 'steady-street', 'cost_units' => 1],
        ];
        $shared = [
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('steady-hands', 'pass', [], 'pass', []),
            $this->clip('steady-sign', 'fail', ['text_artifact'], 'fail', ['text_artifact']),
            $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
        ];
        $gemini = $this->geminiRun([
            ...$shared,
            $this->clip('warp-car', 'pass', [], 'fail', ['object_warp']),
        ], ledger: $ledger);
        $openai = $this->openAiRun([
            ...$shared,
            $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
        ], ledger: $ledger);

        $exit = $this->agreement($gemini, $openai);

        $section = implode("\n", [
            '## Judge agreement',
            '',
            '- Run '.$gemini->id.': gemini gemini-3.5-flash-lite complete',
            '- Run '.$openai->id.': openai gpt-5.4-mini complete',
            '- Rubric: '.self::RUBRIC,
            '- Golden set: golden',
            '- Clips compared: 6',
            '- Clips left out: none',
            '- Agreement rate: 0.833',
            '- warp-car: gemini gemini-3.5-flash-lite pass (), openai gpt-5.4-mini fail (object_warp), human fail (object_warp)',
        ])."\n";

        $this->assertSame(0, $exit);
        $this->assertSame($section, Artisan::output());
        $this->assertSame($section, file_get_contents($this->report));

        $gemini->refresh();
        $openai->refresh();
        $this->assertSame($ledger, $gemini->ledger);
        $this->assertSame($ledger, $openai->ledger);
        $this->assertCount(6, $gemini->results);
        $this->assertCount(6, $openai->results);
    }

    public function test_an_unknown_run_fails_without_changing_the_report(): void
    {
        file_put_contents($this->report, self::SENTINEL);
        $run = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]);

        $exit = Artisan::call('eval:agreement', [
            'runA' => $run->id,
            'runB' => 9999,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Eval run 9999 was not found.', Artisan::output());
        $this->assertSame(self::SENTINEL, file_get_contents($this->report));
    }

    public function test_runs_on_different_golden_sets_fail_without_changing_the_report(): void
    {
        file_put_contents($this->report, self::SENTINEL);
        $left = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]);
        $right = $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])], dataset: 'other');

        $this->assertSame(1, $this->agreement($left, $right));
        $this->assertStringContainsString(
            "Eval runs {$left->id} and {$right->id} use different golden sets (golden and other).",
            Artisan::output(),
        );
        $this->assertSame(self::SENTINEL, file_get_contents($this->report));
    }

    public function test_runs_on_different_rubric_versions_fail_without_changing_the_report(): void
    {
        file_put_contents($this->report, self::SENTINEL);
        $left = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]);
        $right = $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])], rubric: self::OTHER_RUBRIC);

        $this->assertSame(1, $this->agreement($left, $right));
        $this->assertStringContainsString(
            "Eval runs {$left->id} and {$right->id} use different rubric versions.",
            Artisan::output(),
        );
        $this->assertSame(self::SENTINEL, file_get_contents($this->report));
    }

    public function test_the_same_judge_fails_without_changing_the_report(): void
    {
        file_put_contents($this->report, self::SENTINEL);
        $left = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]);
        $right = $this->geminiRun([$this->clip('steady-street', 'fail', [], 'pass', [])]);

        $this->assertSame(1, $this->agreement($left, $right));
        $this->assertStringContainsString(
            "Eval runs {$left->id} and {$right->id} use the same judge (gemini gemini-3.5-flash-lite).",
            Artisan::output(),
        );
        $this->assertSame(self::SENTINEL, file_get_contents($this->report));
    }

    public function test_an_existing_report_keeps_its_other_sections_byte_for_byte(): void
    {
        $prefix = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents($this->report, $prefix."## Judge agreement\n\n- Agreement rate: 0.000\n\n".$suffix);

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.$output."\n".$suffix, file_get_contents($this->report));
        $this->assertStringContainsString('- Agreement rate: 1.000', $output);
    }

    public function test_the_last_section_is_replaced_and_the_rest_keeps_its_bytes(): void
    {
        $prefix = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n\n";
        file_put_contents($this->report, $prefix."## Judge agreement\n\n- Agreement rate: 0.000\n");

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.Artisan::output(), file_get_contents($this->report));
    }

    public function test_a_similar_heading_is_not_the_judge_agreement_section(): void
    {
        $prefix = "# Eval report\n\n## Judge agreements (old)\n\n- Agreement rate: 0.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents($this->report, $prefix."## Judge agreement\n\n- Agreement rate: 0.100\n\n".$suffix);

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.Artisan::output()."\n".$suffix, file_get_contents($this->report));
    }

    public function test_a_heading_inside_a_code_block_does_not_end_the_section(): void
    {
        $prefix = "# Eval report\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents(
            $this->report,
            $prefix."## Judge agreement\n\n```\n## Inside the fence\n```\n\n- Agreement rate: 0.000\n\n".$suffix,
        );

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.Artisan::output()."\n".$suffix, file_get_contents($this->report));
    }

    public function test_an_unclosed_code_block_keeps_the_sections_after_it(): void
    {
        $prefix = "# Eval report\n\n";
        $suffix = "## Disagreements\n\n- keep me\n";
        file_put_contents($this->report, $prefix."## Judge agreement\n\n```\nstray\n\n".$suffix);

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.Artisan::output()."\n".$suffix, file_get_contents($this->report));
    }

    public function test_a_windows_report_keeps_its_bytes_and_line_endings(): void
    {
        $prefix = "# Eval report\r\n\r\n## Metrics\r\n\r\n- Precision: 1.000\r\n\r\n";
        $suffix = "## Disagreements\r\n\r\n- warp-car: human fail (object_warp)\r\n";
        file_put_contents($this->report, $prefix."## Judge agreement\r\n\r\n- Agreement rate: 0.000\r\n\r\n".$suffix);

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $section = str_replace("\n", "\r\n", Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame($prefix.$section."\r\n".$suffix, file_get_contents($this->report));
    }

    public function test_a_report_without_the_section_keeps_its_bytes_and_gains_judge_agreement(): void
    {
        $original = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n";
        file_put_contents($this->report, $original);

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'fail', [], 'pass', [])]),
        );

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame($original."\n".$output, file_get_contents($this->report));
        $this->assertStringStartsWith('## Judge agreement', $output);
    }

    public function test_a_report_without_a_trailing_newline_keeps_its_bytes(): void
    {
        file_put_contents($this->report, 'SENTINEL');

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame("SENTINEL\n\n".$output, file_get_contents($this->report));
        $this->assertStringStartsWith('## Judge agreement', $output);
    }

    public function test_clips_left_out_are_named_and_partial_runs_are_accepted(): void
    {
        $left = $this->geminiRun([
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker']),
        ], status: EvalRunStatus::Partial);
        $right = $this->openAiRun([
            $this->clip('steady-street', 'fail', [], 'pass', []),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
        ], status: EvalRunStatus::Partial);

        $this->assertSame(0, $this->agreement($left, $right));

        $section = file_get_contents($this->report);

        $this->assertIsString($section);
        $this->assertSame($section, Artisan::output());
        $this->assertStringContainsString('- Run '.$left->id.': gemini gemini-3.5-flash-lite partial', $section);
        $this->assertStringContainsString('- Run '.$right->id.': openai gpt-5.4-mini partial', $section);
        $this->assertStringContainsString('- Clips compared: 1', $section);
        $this->assertStringContainsString('  - flicker-neon: error in run '.$left->id, $section);
        $this->assertStringContainsString('  - text-marquee: not reached in run '.$left->id, $section);
        $this->assertStringContainsString('- Agreement rate: 0.000', $section);
        $this->assertStringContainsString(
            '- steady-street: gemini gemini-3.5-flash-lite pass (), openai gpt-5.4-mini fail (), human pass ()',
            $section,
        );
    }

    public function test_a_golden_clip_neither_run_reached_is_named(): void
    {
        $left = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])], status: EvalRunStatus::Partial);
        $right = $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])], status: EvalRunStatus::Partial);

        $this->assertSame(0, $this->agreement($left, $right));

        $neitherReached = 'not reached in run '.$left->id.', not reached in run '.$right->id;

        $this->assertStringContainsString(implode("\n", [
            '- Clips compared: 1',
            '- Clips left out:',
            '  - steady-hands: '.$neitherReached,
            '  - steady-sign: '.$neitherReached,
            '  - flicker-neon: '.$neitherReached,
            '  - warp-car: '.$neitherReached,
            '  - text-marquee: '.$neitherReached,
            '- Agreement rate: 1.000',
        ]), Artisan::output());
    }

    public function test_no_clips_to_compare_writes_an_na_rate_and_succeeds(): void
    {
        $left = $this->geminiRun([$this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker'])]);
        $right = $this->openAiRun([]);

        $this->assertSame(0, $this->agreement($left, $right));

        $section = file_get_contents($this->report);

        $this->assertIsString($section);
        $this->assertStringStartsWith('## Judge agreement', $section);
        $this->assertSame($section, Artisan::output());
        $this->assertStringContainsString('- Clips compared: 0', $section);
        $this->assertStringContainsString('- Agreement rate: n/a', $section);
        $this->assertStringContainsString(
            '  - flicker-neon: error in run '.$left->id.', not reached in run '.$right->id,
            $section,
        );
    }

    public function test_two_models_of_the_same_driver_can_be_compared(): void
    {
        $left = $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]);
        $right = $this->storedRun('gemini', 'gemini-2.5-pro', [
            $this->clip('steady-street', 'fail', ['anatomy'], 'pass', []),
        ]);

        $this->assertSame(0, $this->agreement($left, $right));

        $section = file_get_contents($this->report);

        $this->assertIsString($section);
        $this->assertStringContainsString('- Agreement rate: 0.000', $section);
        $this->assertStringContainsString(
            '- steady-street: gemini gemini-3.5-flash-lite pass (), gemini gemini-2.5-pro fail (anatomy), human pass ()',
            $section,
        );
    }

    public function test_a_report_that_cannot_be_written_fails_with_a_clear_error(): void
    {
        $this->partialMock(JudgeAgreementReport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('write')->andThrow(new RuntimeException('Could not write evals/REPORT.md.'));
        });

        $exit = $this->agreement(
            $this->geminiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
            $this->openAiRun([$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not write evals/REPORT.md.', $output);
        $this->assertStringNotContainsString('## Judge agreement', $output);
    }

    private function agreement(EvalRun $left, EvalRun $right): int
    {
        return Artisan::call('eval:agreement', [
            'runA' => $left->id,
            'runB' => $right->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $ledger
     */
    private function geminiRun(
        array $results,
        string $rubric = self::RUBRIC,
        string $dataset = 'golden',
        array $ledger = [],
        EvalRunStatus $status = EvalRunStatus::Complete,
    ): EvalRun {
        return $this->storedRun('gemini', 'gemini-3.5-flash-lite', $results, $rubric, $dataset, $ledger, $status);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $ledger
     */
    private function openAiRun(
        array $results,
        string $rubric = self::RUBRIC,
        string $dataset = 'golden',
        array $ledger = [],
        EvalRunStatus $status = EvalRunStatus::Complete,
    ): EvalRun {
        return $this->storedRun('openai', 'gpt-5.4-mini', $results, $rubric, $dataset, $ledger, $status);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $ledger
     */
    private function storedRun(
        string $driver,
        string $model,
        array $results,
        string $rubric = self::RUBRIC,
        string $dataset = 'golden',
        array $ledger = [],
        EvalRunStatus $status = EvalRunStatus::Complete,
    ): EvalRun {
        return EvalRun::query()->create([
            'judge_driver' => $driver,
            'judge_model' => $model,
            'rubric_version' => $rubric,
            'dataset' => $dataset,
            'status' => $status,
            'metrics' => [],
            'results' => $results,
            'ledger' => $ledger,
        ]);
    }
}
