<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\MetricFormat;

/**
 * How often two eval runs give the same verdict on the clips both judged.
 */
final class JudgeAgreement
{
    /**
     * @param  list<array{id: string, reason: string}>  $leftOut
     * @param  list<array{id: string, left_verdict: string, left_tags: list<string>, right_verdict: string, right_tags: list<string>, human_verdict: string, human_tags: list<string>}>  $disagreements
     */
    private function __construct(
        public readonly int $compared,
        public readonly float|string $rate,
        public readonly array $leftOut,
        public readonly array $disagreements,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $leftResults
     * @param  list<array<string, mixed>>  $rightResults
     */
    public static function between(int|string $leftRunId, array $leftResults, int|string $rightRunId, array $rightResults): self
    {
        $left = self::index($leftResults);
        $right = self::index($rightResults);
        $compared = 0;
        $agreed = 0;
        $leftOut = [];
        $disagreements = [];

        foreach (self::ids($left, $right) as $id) {
            $reasons = self::reasons($leftRunId, $left[$id] ?? null, $rightRunId, $right[$id] ?? null);

            if ($reasons !== []) {
                $leftOut[] = [
                    'id' => $id,
                    'reason' => implode(', ', $reasons),
                ];

                continue;
            }

            $leftResult = $left[$id];
            $rightResult = $right[$id];
            $compared++;

            if ($leftResult['verdict'] === $rightResult['verdict']) {
                $agreed++;

                continue;
            }

            $disagreements[] = [
                'id' => $id,
                'left_verdict' => $leftResult['verdict'],
                'left_tags' => array_values($leftResult['tags']),
                'right_verdict' => $rightResult['verdict'],
                'right_tags' => array_values($rightResult['tags']),
                'human_verdict' => $leftResult['human']['verdict'],
                'human_tags' => array_values($leftResult['human']['tags']),
            ];
        }

        return new self(
            compared: $compared,
            rate: $compared === 0 ? MetricFormat::NOT_APPLICABLE : $agreed / $compared,
            leftOut: $leftOut,
            disagreements: $disagreements,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return list<string>
     */
    private static function ids(array $left, array $right): array
    {
        $ids = array_keys($left);

        foreach (array_keys($right) as $id) {
            if (! isset($left[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>|null  $leftResult
     * @param  array<string, mixed>|null  $rightResult
     * @return list<string>
     */
    private static function reasons(int|string $leftRunId, ?array $leftResult, int|string $rightRunId, ?array $rightResult): array
    {
        $reasons = [];

        if ($leftResult === null) {
            $reasons[] = "not reached in run {$leftRunId}";
        } elseif (! self::hasVerdict($leftResult)) {
            $reasons[] = "error in run {$leftRunId}";
        }

        if ($rightResult === null) {
            $reasons[] = "not reached in run {$rightRunId}";
        } elseif (! self::hasVerdict($rightResult)) {
            $reasons[] = "error in run {$rightRunId}";
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasVerdict(array $result): bool
    {
        return ($result['verdict'] ?? null) === 'pass' || ($result['verdict'] ?? null) === 'fail';
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    private static function index(array $results): array
    {
        $indexed = [];

        foreach ($results as $result) {
            if (! is_array($result) || ! is_string($result['id'] ?? null) || isset($indexed[$result['id']])) {
                continue;
            }

            $indexed[$result['id']] = $result;
        }

        return $indexed;
    }
}
