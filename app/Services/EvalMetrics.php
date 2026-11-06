<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EvalClipResult;
use App\Services\Judge\JudgeRubric;
use App\Support\MetricFormat;
use Closure;

class EvalMetrics
{
    /**
     * Fail is the positive class. Error clips are left out.
     *
     * @param  list<EvalClipResult>  $results
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
        $scored = self::where($results, fn (EvalClipResult $result): bool => ! $result->isError());
        $judgeFails = self::where($scored, fn (EvalClipResult $result): bool => $result->judgeFailed());
        $humanFails = self::where($scored, fn (EvalClipResult $result): bool => $result->humanFailed());

        return [
            'precision' => self::share($judgeFails, fn (EvalClipResult $result): bool => $result->humanFailed()),
            'recall' => self::share($humanFails, fn (EvalClipResult $result): bool => $result->judgeFailed()),
            'per_tag_recall' => self::perTagRecall($scored),
            'judge_pass_rate' => self::share($scored, fn (EvalClipResult $result): bool => ! $result->judgeFailed()),
            'cost_units' => $costUnits,
        ];
    }

    /**
     * @param  list<EvalClipResult>  $scored
     * @return array<string, float|string>
     */
    private static function perTagRecall(array $scored): array
    {
        $recall = [];

        foreach (JudgeRubric::HARD_FAIL_TAGS as $tag) {
            $humanTagged = self::where($scored, fn (EvalClipResult $result): bool => $result->humanTagged($tag));
            $recall[$tag] = self::share($humanTagged, fn (EvalClipResult $result): bool => $result->judgeTagged($tag));
        }

        return $recall;
    }

    /**
     * @param  list<EvalClipResult>  $results
     * @param  Closure(EvalClipResult): bool  $test
     * @return list<EvalClipResult>
     */
    private static function where(array $results, Closure $test): array
    {
        return array_values(array_filter($results, $test));
    }

    /**
     * Share of the results that pass the test, or n/a for an empty list.
     *
     * @param  list<EvalClipResult>  $results
     * @param  Closure(EvalClipResult): bool  $test
     */
    private static function share(array $results, Closure $test): float|string
    {
        if ($results === []) {
            return MetricFormat::NOT_APPLICABLE;
        }

        return count(self::where($results, $test)) / count($results);
    }
}
