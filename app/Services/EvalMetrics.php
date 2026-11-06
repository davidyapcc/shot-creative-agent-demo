<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Judge\JudgeRubric;

class EvalMetrics
{
    /**
     * Fail is the positive class. Error clips are left out.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array{
     *     precision: float|string,
     *     recall: float|string,
     *     per_tag_recall: array<string, float|string>,
     *     judge_pass_rate: float|string,
     *     cost_units: int
     * }
     */
    public static function score(array $results, int $costUnits): array
    {
        $scored = array_values(array_filter(
            $results,
            fn (array $result): bool => ($result['verdict'] ?? null) !== 'error',
        ));

        $judgeFails = array_values(array_filter(
            $scored,
            fn (array $result): bool => ($result['verdict'] ?? null) === 'fail',
        ));

        $humanFails = array_values(array_filter(
            $scored,
            fn (array $result): bool => ($result['human']['verdict'] ?? null) === 'fail',
        ));

        $truePositives = 0;

        foreach ($judgeFails as $result) {
            if (($result['human']['verdict'] ?? null) === 'fail') {
                $truePositives++;
            }
        }

        $recalled = 0;

        foreach ($humanFails as $result) {
            if (($result['verdict'] ?? null) === 'fail') {
                $recalled++;
            }
        }

        $passes = 0;

        foreach ($scored as $result) {
            if (($result['verdict'] ?? null) === 'pass') {
                $passes++;
            }
        }

        return [
            'precision' => $judgeFails === [] ? 'n/a' : $truePositives / count($judgeFails),
            'recall' => $humanFails === [] ? 'n/a' : $recalled / count($humanFails),
            'per_tag_recall' => self::perTagRecall($scored),
            'judge_pass_rate' => $scored === [] ? 'n/a' : $passes / count($scored),
            'cost_units' => $costUnits,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return array<string, float|string>
     */
    private static function perTagRecall(array $scored): array
    {
        $recall = [];

        foreach (JudgeRubric::HARD_FAIL_TAGS as $tag) {
            $humanUsed = array_values(array_filter(
                $scored,
                fn (array $result): bool => in_array($tag, $result['human']['tags'] ?? [], true),
            ));

            if ($humanUsed === []) {
                $recall[$tag] = 'n/a';

                continue;
            }

            $hit = 0;

            foreach ($humanUsed as $result) {
                if (in_array($tag, $result['tags'] ?? [], true)) {
                    $hit++;
                }
            }

            $recall[$tag] = $hit / count($humanUsed);
        }

        return $recall;
    }
}
