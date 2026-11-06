<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EvalRun;
use Illuminate\Console\Command;

class EvalDiffCommand extends Command
{
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
                $left->metrics[$metric] ?? 'n/a',
                $right->metrics[$metric] ?? 'n/a',
            ));
        }

        $tags = array_values(array_unique(array_merge(
            array_keys($left->metrics['per_tag_recall'] ?? []),
            array_keys($right->metrics['per_tag_recall'] ?? []),
        )));

        foreach ($tags as $tag) {
            $this->line($this->metricLine(
                $tag,
                $left->metrics['per_tag_recall'][$tag] ?? 'n/a',
                $right->metrics['per_tag_recall'][$tag] ?? 'n/a',
            ));
        }

        $this->newLine();
        $this->line('Verdict changes');

        foreach ($this->verdictChanges($left, $right) as $change) {
            $this->line($change);
        }

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

    private function header(EvalRun $run): string
    {
        return implode('  ', [
            $run->judge_driver,
            $run->judge_model,
            $run->rubric_version,
            $run->dataset,
            $run->status,
        ]);
    }

    private function metricLine(string $name, mixed $left, mixed $right): string
    {
        $line = $name.'  '.$this->format($left).' -> '.$this->format($right);
        $delta = $this->delta($left, $right);

        if ($delta === null) {
            return $line;
        }

        return $line.'  ('.$delta.')';
    }

    private function format(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 3, '.', '');
        }

        return 'n/a';
    }

    private function delta(mixed $left, mixed $right): ?string
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return null;
        }

        return sprintf('%+.3f', (float) $right - (float) $left);
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
