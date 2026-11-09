<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvalRun;
use App\Support\MetricFormat;
use RuntimeException;

/**
 * Renders and writes the judge-agreement section of evals/REPORT.md.
 * The rest of that file is left as it stands.
 */
class JudgeAgreementReport
{
    private const HEADING = '## Judge agreement';

    public function render(EvalRun $left, EvalRun $right, JudgeAgreement $agreement): string
    {
        $lines = [
            self::HEADING,
            '',
            $this->runLine($left),
            $this->runLine($right),
            '- Rubric: '.$left->rubric_version,
            '- Golden set: '.$left->dataset,
            '- Clips compared: '.$agreement->compared,
            ...$this->leftOut($agreement),
            '- Agreement rate: '.MetricFormat::format($agreement->rate),
            ...$this->disagreements($left, $right, $agreement),
        ];

        return implode("\n", $lines)."\n";
    }

    public function write(string $path, string $section): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (! is_file($path)) {
            file_put_contents($path, $section);

            return;
        }

        $existing = file_get_contents($path);

        if ($existing === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        file_put_contents($path, $this->replace($existing, $section));
    }

    private function runLine(EvalRun $run): string
    {
        return '- Run '.$run->id.': '.$run->judge_driver.' '.$run->judge_model.' '.($run->status?->value ?? '');
    }

    /**
     * @return list<string>
     */
    private function leftOut(JudgeAgreement $agreement): array
    {
        if ($agreement->leftOut === []) {
            return ['- Clips left out: none'];
        }

        $lines = ['- Clips left out:'];

        foreach ($agreement->leftOut as $clip) {
            $lines[] = '  - '.$clip['id'].': '.$clip['reason'];
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function disagreements(EvalRun $left, EvalRun $right, JudgeAgreement $agreement): array
    {
        $lines = [];

        foreach ($agreement->disagreements as $clip) {
            $lines[] = sprintf(
                '- %s: %s %s %s (%s), %s %s %s (%s), human %s (%s)',
                $clip['id'],
                $left->judge_driver,
                $left->judge_model,
                $clip['left_verdict'],
                implode(', ', $clip['left_tags']),
                $right->judge_driver,
                $right->judge_model,
                $clip['right_verdict'],
                implode(', ', $clip['right_tags']),
                $clip['human_verdict'],
                implode(', ', $clip['human_tags']),
            );
        }

        return $lines;
    }

    private function replace(string $existing, string $section): string
    {
        $start = $this->headingOffset($existing);

        if ($start === null) {
            if ($existing === '') {
                return $section;
            }

            $separator = match (true) {
                str_ends_with($existing, "\n\n") => '',
                str_ends_with($existing, "\n") => "\n",
                default => "\n\n",
            };

            return $existing.$separator.$section;
        }

        $next = $this->nextHeadingOffset($existing, $start);
        $prefix = substr($existing, 0, $start);

        if ($next === null) {
            return $prefix.$section;
        }

        $inserted = str_ends_with($section, "\n\n") ? $section : rtrim($section, "\n")."\n\n";

        return $prefix.$inserted.substr($existing, $next);
    }

    private function headingOffset(string $existing): ?int
    {
        $heading = preg_quote(self::HEADING, '/');

        if (preg_match('/^'.$heading.'[^\n]*$/m', $existing, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $matches[0][1];
    }

    private function nextHeadingOffset(string $existing, int $start): ?int
    {
        $lineEnd = strpos($existing, "\n", $start);
        $from = $lineEnd === false ? strlen($existing) : $lineEnd + 1;

        if (preg_match('/^## /m', $existing, $matches, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }

        return $matches[0][1];
    }
}
