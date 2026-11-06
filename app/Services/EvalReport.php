<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EvalClipResult;
use App\Models\EvalRun;
use App\Support\MetricFormat;

/**
 * Renders evals/REPORT.md from a complete eval run.
 */
class EvalReport
{
    /**
     * @param  list<EvalClipResult>  $results
     */
    public function write(EvalRun $run, array $results, string $path): void
    {
        $lines = [
            ...$this->header($run),
            ...$this->metrics($run->metrics ?? []),
            ...$this->disagreements($results),
        ];

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    /**
     * @return list<string>
     */
    private function header(EvalRun $run): array
    {
        return [
            '# Eval report',
            '',
            '- Run: '.$run->id,
            '- Driver: '.$run->judge_driver,
            '- Model: '.$run->judge_model,
            '- Rubric: '.$run->rubric_version,
            '- Set: '.$run->dataset,
            '- Status: '.$run->status->value,
            '',
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return list<string>
     */
    private function metrics(array $metrics): array
    {
        $lines = [
            '## Metrics',
            '',
            '- Precision: '.MetricFormat::format($metrics['precision'] ?? null),
            '- Recall: '.MetricFormat::format($metrics['recall'] ?? null),
            '- Judge pass rate: '.MetricFormat::format($metrics['judge_pass_rate'] ?? null),
            '- Cost units: '.($metrics['cost_units'] ?? 0),
            '',
            '## Per-tag recall',
            '',
        ];

        foreach ($metrics['per_tag_recall'] ?? [] as $tag => $recall) {
            $lines[] = '- '.$tag.': '.MetricFormat::format($recall);
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<EvalClipResult>  $results
     * @return list<string>
     */
    private function disagreements(array $results): array
    {
        $lines = ['## Disagreements', ''];

        foreach ($results as $result) {
            if (! $result->disagreesWithHuman()) {
                continue;
            }

            $lines[] = sprintf(
                '- %s: human %s (%s), judge %s (%s)',
                $result->clip->id,
                $result->clip->verdict->value,
                implode(', ', $result->clip->tags),
                $result->verdictLabel(),
                implode(', ', $result->tags),
            );
        }

        return $lines;
    }
}
