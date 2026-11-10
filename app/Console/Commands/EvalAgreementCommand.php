<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\EvalRunResults;
use App\DTOs\GoldenClip;
use App\Models\EvalRun;
use App\Services\GoldenSet;
use App\Services\JudgeAgreement;
use App\Services\JudgeAgreementReport;
use App\Traits\FindsEvalRun;
use Illuminate\Console\Command;
use RuntimeException;

class EvalAgreementCommand extends Command
{
    use FindsEvalRun;

    protected $signature = 'eval:agreement {runA} {runB}';

    protected $description = 'Report judge agreement between two eval runs';

    public function handle(JudgeAgreementReport $report): int
    {
        $left = $this->findRun($this->argument('runA'));
        $right = $this->findRun($this->argument('runB'));

        if ($left === null || $right === null) {
            return self::FAILURE;
        }

        $refusal = $this->refusal($left, $right);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        try {
            $agreement = JudgeAgreement::between(
                $this->goldenClipIds($left->dataset),
                EvalRunResults::fromRun($left),
                EvalRunResults::fromRun($right),
            );
            $section = $report->render($left, $right, $agreement);
            $report->write(base_path('evals/REPORT.md'), $section);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->write($section);

        return self::SUCCESS;
    }

    private function refusal(EvalRun $left, EvalRun $right): ?string
    {
        if ($left->dataset !== $right->dataset) {
            return "Eval runs {$left->id} and {$right->id} use different golden sets ({$left->dataset} and {$right->dataset}).";
        }

        if ($left->rubric_version !== $right->rubric_version) {
            return "Eval runs {$left->id} and {$right->id} use different rubric versions.";
        }

        if ($left->judge_driver === $right->judge_driver && $left->judge_model === $right->judge_model) {
            return "Eval runs {$left->id} and {$right->id} use the same judge ({$left->judge_driver} {$left->judge_model}).";
        }

        return null;
    }

    /**
     * The golden set's clip ids, so a clip neither run reached is still named.
     * Only the golden set is on disk; any other set compares stored results alone.
     *
     * @return list<string>
     */
    private function goldenClipIds(?string $dataset): array
    {
        if ($dataset !== GoldenSet::NAME) {
            return [];
        }

        return array_map(
            fn (GoldenClip $clip): string => $clip->id,
            GoldenSet::load(base_path('evals/golden.json')),
        );
    }
}
