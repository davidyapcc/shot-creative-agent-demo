<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EvalRunResults;
use App\Enums\JudgementVerdict;
use App\Support\MetricFormat;

/**
 * How often two eval runs give the same verdict on the clips both judged.
 */
final class JudgeAgreement
{
    /**
     * @param  list<array{id: string, reason: string}>  $clipsLeftOut
     * @param  list<array{id: string, left_verdict: JudgementVerdict, left_tags: list<string>, right_verdict: JudgementVerdict, right_tags: list<string>, human_verdict: string, human_tags: list<string>}>  $disagreements
     */
    private function __construct(
        public readonly int $compared,
        public readonly float|string $rate,
        public readonly array $clipsLeftOut,
        public readonly array $disagreements,
    ) {}

    /**
     * Golden clips come first, in golden-set order, so a clip neither run
     * reached is still named. Results for clips outside the golden set follow.
     *
     * @param  list<string>  $goldenClipIds
     */
    public static function between(array $goldenClipIds, EvalRunResults $left, EvalRunResults $right): self
    {
        $leftById = self::index($left->results);
        $rightById = self::index($right->results);
        $compared = 0;
        $agreed = 0;
        $clipsLeftOut = [];
        $disagreements = [];

        foreach (self::clipIds($goldenClipIds, $leftById, $rightById) as $id) {
            $leftResult = $leftById[$id] ?? null;
            $rightResult = $rightById[$id] ?? null;
            $reasons = [
                ...self::reasons($left->runId, $leftResult),
                ...self::reasons($right->runId, $rightResult),
            ];

            if ($reasons !== []) {
                $clipsLeftOut[] = ['id' => $id, 'reason' => implode(', ', $reasons)];

                continue;
            }

            $compared++;
            $leftVerdict = self::verdict($leftResult);
            $rightVerdict = self::verdict($rightResult);

            if ($leftVerdict === $rightVerdict) {
                $agreed++;

                continue;
            }

            $disagreements[] = self::disagreement($id, $leftVerdict, $leftResult, $rightVerdict, $rightResult);
        }

        return new self(
            compared: $compared,
            rate: $compared === 0 ? MetricFormat::NOT_APPLICABLE : $agreed / $compared,
            clipsLeftOut: $clipsLeftOut,
            disagreements: $disagreements,
        );
    }

    /**
     * @param  list<string>  $goldenClipIds
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return list<string>
     */
    private static function clipIds(array $goldenClipIds, array $left, array $right): array
    {
        return array_values(array_unique([
            ...$goldenClipIds,
            ...array_map('strval', array_keys($left)),
            ...array_map('strval', array_keys($right)),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<string>
     */
    private static function reasons(int|string $runId, ?array $result): array
    {
        if ($result === null) {
            return ["not reached in run {$runId}"];
        }

        if (self::verdict($result) === null) {
            return ["error in run {$runId}"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private static function verdict(?array $result): ?JudgementVerdict
    {
        $verdict = $result['verdict'] ?? null;

        return is_string($verdict) ? JudgementVerdict::tryFrom($verdict) : null;
    }

    /**
     * @param  array<string, mixed>  $leftResult
     * @param  array<string, mixed>  $rightResult
     * @return array{id: string, left_verdict: JudgementVerdict, left_tags: list<string>, right_verdict: JudgementVerdict, right_tags: list<string>, human_verdict: string, human_tags: list<string>}
     */
    private static function disagreement(
        string $id,
        JudgementVerdict $leftVerdict,
        array $leftResult,
        JudgementVerdict $rightVerdict,
        array $rightResult,
    ): array {
        return [
            'id' => $id,
            'left_verdict' => $leftVerdict,
            'left_tags' => array_values($leftResult['tags'] ?? []),
            'right_verdict' => $rightVerdict,
            'right_tags' => array_values($rightResult['tags'] ?? []),
            'human_verdict' => (string) ($leftResult['human']['verdict'] ?? ''),
            'human_tags' => array_values($leftResult['human']['tags'] ?? []),
        ];
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
