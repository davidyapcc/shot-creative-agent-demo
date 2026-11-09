<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EvalRunStatus;
use App\Models\EvalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvalAgreementCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_runs_report_the_agreement_rate_and_the_disagreeing_clip(): void
    {
        Http::preventStrayRequests();
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        if (is_file($report)) {
            unlink($report);
        }

        $rubric = str_repeat('a', 64);
        $ledger = [
            ['clip_id' => 'steady-street', 'cost_units' => 1],
        ];
        $gemini = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('steady-hands', 'pass', [], 'pass', []),
            $this->clip('steady-sign', 'fail', ['text_artifact'], 'fail', ['text_artifact']),
            $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
            $this->clip('warp-car', 'pass', [], 'fail', ['object_warp']),
        ], $ledger);
        $openai = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('steady-hands', 'pass', [], 'pass', []),
            $this->clip('steady-sign', 'fail', ['text_artifact'], 'fail', ['text_artifact']),
            $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
            $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
        ], $ledger);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $gemini->id,
                'runB' => $openai->id,
            ]);

            $section = implode("\n", [
                '## Judge agreement',
                '',
                '- Run '.$gemini->id.': gemini gemini-3.5-flash-lite complete',
                '- Run '.$openai->id.': openai gpt-5.4-mini complete',
                '- Rubric: '.$rubric,
                '- Golden set: golden',
                '- Clips compared: 6',
                '- Clips left out: none',
                '- Agreement rate: 0.833',
                '- warp-car: gemini gemini-3.5-flash-lite pass (), openai gpt-5.4-mini fail (object_warp), human fail (object_warp)',
            ])."\n";

            $this->assertSame(0, $exit);
            $this->assertSame($section, Artisan::output());
            $this->assertSame($section, file_get_contents($report));

            $gemini->refresh();
            $openai->refresh();
            $this->assertSame($ledger, $gemini->ledger);
            $this->assertSame($ledger, $openai->ledger);
            $this->assertCount(6, $gemini->results);
            $this->assertCount(6, $openai->results);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_an_unknown_run_fails_without_changing_the_report(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, "SENTINEL\n");
        $run = $this->storedRun('gemini', 'gemini-3.5-flash-lite', str_repeat('a', 64), 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $run->id,
                'runB' => 9999,
            ]);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Eval run 9999 was not found.', Artisan::output());
            $this->assertSame("SENTINEL\n", file_get_contents($report));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_runs_on_different_golden_sets_fail_without_changing_the_report(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, "SENTINEL\n");
        $rubric = str_repeat('a', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'other', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString(
                "Eval runs {$left->id} and {$right->id} use different golden sets (golden and other).",
                Artisan::output(),
            );
            $this->assertSame("SENTINEL\n", file_get_contents($report));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_runs_on_different_rubric_versions_fail_without_changing_the_report(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, "SENTINEL\n");
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', str_repeat('a', 64), 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', str_repeat('b', 64), 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString(
                "Eval runs {$left->id} and {$right->id} use different rubric versions.",
                Artisan::output(),
            );
            $this->assertSame("SENTINEL\n", file_get_contents($report));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_the_same_judge_fails_without_changing_the_report(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, "SENTINEL\n");
        $rubric = str_repeat('a', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'fail', [], 'pass', []),
        ], []);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString(
                "Eval runs {$left->id} and {$right->id} use the same judge (gemini gemini-3.5-flash-lite).",
                Artisan::output(),
            );
            $this->assertSame("SENTINEL\n", file_get_contents($report));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_an_existing_report_keeps_its_other_sections_and_replaces_judge_agreement(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        $prefix = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents($report, $prefix."## Judge agreement\n\n- Agreement rate: 0.000\n\n".$suffix);
        $rubric = str_repeat('c', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $exit = Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]);

            $after = file_get_contents($report);

            $this->assertSame(0, $exit);
            $this->assertIsString($after);
            $this->assertStringStartsWith($prefix, $after);
            $this->assertStringEndsWith($suffix, $after);
            $this->assertStringNotContainsString('0.000', $after);
            $this->assertStringContainsString('- Agreement rate: 1.000', $after);
            $this->assertStringContainsString('- Clips compared: 1', $after);
            $this->assertSame(1, substr_count($after, '## Judge agreement'));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_similar_heading_is_not_the_judge_agreement_section(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        $prefix = "# Eval report\n\n## Judge agreements (old)\n\n- Agreement rate: 0.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents($report, $prefix."## Judge agreement\n\n- Agreement rate: 0.100\n\n".$suffix);
        $rubric = str_repeat('c', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $after = file_get_contents($report);

            $this->assertIsString($after);
            $this->assertStringStartsWith($prefix, $after);
            $this->assertStringEndsWith($suffix, $after);
            $this->assertStringNotContainsString('0.100', $after);
            $this->assertStringContainsString('- Agreement rate: 1.000', $after);
            $this->assertSame(1, substr_count($after, "## Judge agreement\n"));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_heading_inside_a_code_block_does_not_end_the_section(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        $prefix = "# Eval report\n\n";
        $fenced = "```\n## Inside the fence\n```\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";
        file_put_contents(
            $report,
            $prefix."## Judge agreement\n\n".$fenced."\n- Agreement rate: 0.000\n\n".$suffix,
        );
        $rubric = str_repeat('c', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $after = file_get_contents($report);

            $this->assertIsString($after);
            $this->assertStringStartsWith($prefix, $after);
            $this->assertStringEndsWith($suffix, $after);
            $this->assertStringNotContainsString('0.000', $after);
            $this->assertStringNotContainsString($fenced, $after);
            $this->assertStringContainsString('- Agreement rate: 1.000', $after);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_windows_heading_is_replaced_and_the_other_sections_keep_their_bytes(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        $prefix = "# Eval report\r\n\r\n## Metrics\r\n\r\n- Precision: 1.000\r\n\r\n";
        $suffix = "## Disagreements\r\n\r\n- warp-car: human fail (object_warp)\r\n";
        file_put_contents($report, $prefix."## Judge agreement\r\n\r\n- Agreement rate: 0.000\r\n\r\n".$suffix);
        $rubric = str_repeat('c', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $after = file_get_contents($report);

            $this->assertIsString($after);
            $this->assertStringStartsWith($prefix, $after);
            $this->assertStringEndsWith($suffix, $after);
            $this->assertStringNotContainsString('0.000', $after);
            $this->assertStringContainsString('- Agreement rate: 1.000', $after);
            $this->assertSame(1, substr_count($after, '## Judge agreement'));
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_report_without_the_section_keeps_its_bytes_and_gains_judge_agreement(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        $original = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n";
        file_put_contents($report, $original);
        $rubric = str_repeat('d', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'fail', [], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $after = file_get_contents($report);
            $output = Artisan::output();

            $this->assertIsString($after);
            $this->assertSame($original."\n".$output, $after);
            $this->assertStringStartsWith('## Judge agreement', $output);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_a_report_without_a_trailing_newline_keeps_its_bytes(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        file_put_contents($report, 'SENTINEL');
        $rubric = str_repeat('d', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $after = file_get_contents($report);
            $output = Artisan::output();

            $this->assertIsString($after);
            $this->assertSame("SENTINEL\n\n".$output, $after);
            $this->assertStringStartsWith('## Judge agreement', $output);
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_clips_left_out_are_named_and_partial_runs_are_accepted(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        if (is_file($report)) {
            unlink($report);
        }

        $rubric = str_repeat('e', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker']),
        ], [], EvalRunStatus::Partial);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [
            $this->clip('steady-street', 'fail', [], 'pass', []),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
        ], [], EvalRunStatus::Partial);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $section = file_get_contents($report);

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
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_no_clips_to_compare_writes_an_na_rate_and_succeeds(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        if (is_file($report)) {
            unlink($report);
        }

        $rubric = str_repeat('f', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker']),
        ], []);
        $right = $this->storedRun('openai', 'gpt-5.4-mini', $rubric, 'golden', [], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $section = file_get_contents($report);

            $this->assertIsString($section);
            $this->assertStringStartsWith('## Judge agreement', $section);
            $this->assertSame($section, Artisan::output());
            $this->assertStringContainsString('- Clips compared: 0', $section);
            $this->assertStringContainsString('- Agreement rate: n/a', $section);
            $this->assertStringContainsString(
                '  - flicker-neon: error in run '.$left->id.', not reached in run '.$right->id,
                $section,
            );
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    public function test_two_models_of_the_same_driver_can_be_compared(): void
    {
        $report = base_path('evals/REPORT.md');
        $previous = is_file($report) ? file_get_contents($report) : null;
        if (is_file($report)) {
            unlink($report);
        }

        $rubric = str_repeat('a', 64);
        $left = $this->storedRun('gemini', 'gemini-3.5-flash-lite', $rubric, 'golden', [
            $this->clip('steady-street', 'pass', [], 'pass', []),
        ], []);
        $right = $this->storedRun('gemini', 'gemini-2.5-pro', $rubric, 'golden', [
            $this->clip('steady-street', 'fail', ['anatomy'], 'pass', []),
        ], []);

        try {
            $this->assertSame(0, Artisan::call('eval:agreement', [
                'runA' => $left->id,
                'runB' => $right->id,
            ]));

            $section = file_get_contents($report);

            $this->assertIsString($section);
            $this->assertStringContainsString('- Agreement rate: 0.000', $section);
            $this->assertStringContainsString(
                '- steady-street: gemini gemini-3.5-flash-lite pass (), gemini gemini-2.5-pro fail (anatomy), human pass ()',
                $section,
            );
        } finally {
            $this->restoreReport($report, $previous);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $ledger
     */
    private function storedRun(
        string $driver,
        string $model,
        string $rubric,
        string $dataset,
        array $results,
        array $ledger,
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

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $humanTags
     * @return array<string, mixed>
     */
    private function clip(string $id, string $verdict, array $tags, string $humanVerdict, array $humanTags): array
    {
        return [
            'id' => $id,
            'verdict' => $verdict,
            'tags' => $tags,
            'human' => [
                'verdict' => $humanVerdict,
                'tags' => $humanTags,
            ],
        ];
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
