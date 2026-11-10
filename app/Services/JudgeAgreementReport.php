<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvalRun;
use App\Support\MarkdownSection;
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
            ...$this->clipsLeftOut($agreement),
            '- Agreement rate: '.MetricFormat::format($agreement->rate),
            ...$this->disagreements($left, $right, $agreement),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Replaces the judge-agreement section of the report at `$path`, or creates
     * the report with only that section.
     *
     * @throws RuntimeException when the report cannot be read or written
     */
    public function write(string $path, string $section): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw $this->failure("Could not create {$directory}");
        }

        $existing = is_file($path) ? @file_get_contents($path) : '';

        if ($existing === false) {
            throw $this->failure("Could not read {$path}");
        }

        if (@file_put_contents($path, MarkdownSection::replace($existing, self::HEADING, $section)) === false) {
            throw $this->failure("Could not write {$path}");
        }
    }

    /**
     * Keeps the filesystem's own reason, which the `@` above held back from a warning.
     */
    private function failure(string $what): RuntimeException
    {
        $reason = error_get_last()['message'] ?? null;

        return new RuntimeException($reason === null ? "{$what}." : "{$what}: {$reason}");
    }

    private function runLine(EvalRun $run): string
    {
        return '- Run '.$run->id.': '.$run->judge_driver.' '.$run->judge_model.' '.($run->status?->value ?? '');
    }

    /**
     * @return list<string>
     */
    private function clipsLeftOut(JudgeAgreement $agreement): array
    {
        if ($agreement->clipsLeftOut === []) {
            return ['- Clips left out: none'];
        }

        $lines = ['- Clips left out:'];

        foreach ($agreement->clipsLeftOut as $clip) {
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
                $clip['left_verdict']->value,
                implode(', ', $clip['left_tags']),
                $right->judge_driver,
                $right->judge_model,
                $clip['right_verdict']->value,
                implode(', ', $clip['right_tags']),
                $clip['human_verdict'],
                implode(', ', $clip['human_tags']),
            );
        }

        return $lines;
    }
}
