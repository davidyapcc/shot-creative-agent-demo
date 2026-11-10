<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EvalRun;
use App\Support\MetricFormat;
use App\Traits\FindsEvalRun;
use Illuminate\Console\Command;

class EvalDiffCommand extends Command
{
    use FindsEvalRun;

    protected $signature = 'eval:diff {runA} {runB}';

    protected $description = 'Compare two eval runs';

    public function handle(): int
    {
        $left = $this->findRun($this->argument('runA'));
        $right = $this->findRun($this->argument('runB'));

        if ($left === null || $right === null) {
            return self::FAILURE;
        }

        $this->line($this->header($left));
        $this->line($this->header($right));
        $this->newLine();

        foreach (['precision', 'recall', 'judge_pass_rate', 'cost_units'] as $metric) {
            $this->line($this->metricLine(
                $metric,
                $left->metrics[$metric] ?? MetricFormat::NOT_APPLICABLE,
                $right->metrics[$metric] ?? MetricFormat::NOT_APPLICABLE,
            ));
        }

        $tags = array_values(array_unique(array_merge(
            array_keys($left->metrics['per_tag_recall'] ?? []),
            array_keys($right->metrics['per_tag_recall'] ?? []),
        )));

        foreach ($tags as $tag) {
            $this->line($this->metricLine(
                $tag,
                $left->metrics['per_tag_recall'][$tag] ?? MetricFormat::NOT_APPLICABLE,
                $right->metrics['per_tag_recall'][$tag] ?? MetricFormat::NOT_APPLICABLE,
            ));
        }

        $this->newLine();
        $this->line('Verdict changes');

        foreach ($this->verdictChanges($left, $right) as $change) {
            $this->line($change);
        }

        return self::SUCCESS;
    }

    private function header(EvalRun $run): string
    {
        return implode('  ', [
            $run->judge_driver,
            $run->judge_model,
            $run->rubric_version,
            $run->dataset,
            $run->status?->value,
        ]);
    }

    private function metricLine(string $name, mixed $left, mixed $right): string
    {
        $line = $name.'  '.MetricFormat::format($left).' -> '.MetricFormat::format($right);
        $delta = MetricFormat::delta($left, $right);

        if ($delta === null) {
            return $line;
        }

        return $line.'  ('.$delta.')';
    }

    /**
     * @return list<string>
     */
    private function verdictChanges(EvalRun $left, EvalRun $right): array
    {
        $leftVerdicts = $this->verdicts($left);
        $rightVerdicts = $this->verdicts($right);
        $changes = [];

        foreach ([...array_keys($leftVerdicts), ...array_keys($rightVerdicts)] as $id) {
            if (array_key_exists($id, $changes)) {
                continue;
            }

            $from = $leftVerdicts[$id] ?? 'not judged';
            $to = $rightVerdicts[$id] ?? 'not judged';

            if ($from === $to) {
                continue;
            }

            $changes[$id] = $id.'  '.$from.' -> '.$to;
        }

        return array_values($changes);
    }

    /**
     * @return array<string, string>
     */
    private function verdicts(EvalRun $run): array
    {
        $verdicts = [];

        foreach ($run->results ?? [] as $result) {
            if (! is_array($result) || ! is_string($result['id'] ?? null)) {
                continue;
            }

            $verdict = $result['verdict'] ?? 'not judged';
            $verdicts[$result['id']] = is_string($verdict) ? $verdict : 'not judged';
        }

        return $verdicts;
    }
}
