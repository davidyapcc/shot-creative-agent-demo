<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EvalRun;
use App\Services\JudgeAgreement;
use App\Services\JudgeAgreementReport;
use Illuminate\Console\Command;

class EvalAgreementCommand extends Command
{
    protected $signature = 'eval:agreement {runA} {runB}';

    protected $description = 'Report judge agreement between two eval runs';

    public function handle(JudgeAgreementReport $report): int
    {
        $left = $this->findRun($this->argument('runA'));
        $right = $this->findRun($this->argument('runB'));

        if ($left === null || $right === null) {
            return self::FAILURE;
        }

        if ($left->dataset !== $right->dataset) {
            $this->error("Eval runs {$left->id} and {$right->id} use different golden sets ({$left->dataset} and {$right->dataset}).");

            return self::FAILURE;
        }

        if ($left->rubric_version !== $right->rubric_version) {
            $this->error("Eval runs {$left->id} and {$right->id} use different rubric versions.");

            return self::FAILURE;
        }

        if ($left->judge_driver === $right->judge_driver && $left->judge_model === $right->judge_model) {
            $this->error("Eval runs {$left->id} and {$right->id} use the same judge ({$left->judge_driver} {$left->judge_model}).");

            return self::FAILURE;
        }

        $agreement = JudgeAgreement::between(
            $left->id,
            $left->results ?? [],
            $right->id,
            $right->results ?? [],
        );
        $section = $report->render($left, $right, $agreement);
        $this->output->write($section);
        $report->write(base_path('evals/REPORT.md'), $section);

        return self::SUCCESS;
    }

    private function findRun(mixed $id): ?EvalRun
    {
        $run = EvalRun::query()->find($id);

        if ($run === null) {
            $this->error("Eval run {$id} was not found.");
        }

        return $run;
    }
}
