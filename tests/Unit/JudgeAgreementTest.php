<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\EvalRunResults;
use App\Enums\JudgementVerdict;
use App\Services\JudgeAgreement;
use Tests\Concerns\BuildsStoredClipResults;
use Tests\TestCase;

class JudgeAgreementTest extends TestCase
{
    use BuildsStoredClipResults;

    private const RATE_DELTA = 0.0000001;

    public function test_five_of_six_clips_agree_and_the_disagreement_lists_both_verdicts(): void
    {
        $shared = [
            $this->clip('steady-street', 'pass', [], 'pass', []),
            $this->clip('steady-hands', 'pass', [], 'pass', []),
            $this->clip('steady-sign', 'fail', ['text_artifact'], 'fail', ['text_artifact']),
            $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
            $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
        ];

        $agreement = JudgeAgreement::between(
            [],
            new EvalRunResults(3, [
                ...$shared,
                $this->clip('warp-car', 'pass', [], 'fail', ['object_warp']),
            ]),
            new EvalRunResults(4, [
                ...$shared,
                $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
            ]),
        );

        $this->assertSame(6, $agreement->compared);
        $this->assertEqualsWithDelta(5 / 6, $agreement->rate, self::RATE_DELTA);
        $this->assertSame([], $agreement->clipsLeftOut);
        $this->assertSame([
            [
                'id' => 'warp-car',
                'left_verdict' => JudgementVerdict::Pass,
                'left_tags' => [],
                'right_verdict' => JudgementVerdict::Fail,
                'right_tags' => ['object_warp'],
                'human_verdict' => 'fail',
                'human_tags' => ['object_warp'],
            ],
        ], $agreement->disagreements);
    }

    public function test_the_same_verdict_with_different_tags_counts_as_agreement(): void
    {
        $agreement = JudgeAgreement::between(
            [],
            new EvalRunResults(1, [$this->clip('text-marquee', 'fail', ['text_artifact'], 'fail', ['text_artifact'])]),
            new EvalRunResults(2, [$this->clip('text-marquee', 'fail', ['object_warp'], 'fail', ['text_artifact'])]),
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertEqualsWithDelta(1, $agreement->rate, self::RATE_DELTA);
        $this->assertSame([], $agreement->disagreements);
        $this->assertSame([], $agreement->clipsLeftOut);
    }

    public function test_an_error_or_a_clip_never_reached_is_left_out_of_the_rate(): void
    {
        $agreement = JudgeAgreement::between(
            [],
            new EvalRunResults(3, [
                $this->clip('steady-street', 'pass', [], 'pass', []),
                $this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker']),
                $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
            ]),
            new EvalRunResults(4, [
                $this->clip('steady-street', 'fail', [], 'pass', []),
                $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
                $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
            ]),
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertEqualsWithDelta(0, $agreement->rate, self::RATE_DELTA);
        $this->assertSame([
            ['id' => 'flicker-neon', 'reason' => 'error in run 3'],
            ['id' => 'warp-car', 'reason' => 'not reached in run 4'],
            ['id' => 'text-marquee', 'reason' => 'not reached in run 3'],
        ], $agreement->clipsLeftOut);
        $this->assertSame([
            [
                'id' => 'steady-street',
                'left_verdict' => JudgementVerdict::Pass,
                'left_tags' => [],
                'right_verdict' => JudgementVerdict::Fail,
                'right_tags' => [],
                'human_verdict' => 'pass',
                'human_tags' => [],
            ],
        ], $agreement->disagreements);
    }

    public function test_a_golden_clip_neither_run_reached_is_named_in_golden_set_order(): void
    {
        $agreement = JudgeAgreement::between(
            ['steady-street', 'warp-car', 'text-marquee'],
            new EvalRunResults(3, [$this->clip('steady-street', 'pass', [], 'pass', [])]),
            new EvalRunResults(4, [$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertSame([
            ['id' => 'warp-car', 'reason' => 'not reached in run 3, not reached in run 4'],
            ['id' => 'text-marquee', 'reason' => 'not reached in run 3, not reached in run 4'],
        ], $agreement->clipsLeftOut);
    }

    public function test_a_result_outside_the_golden_set_is_still_compared_after_the_golden_clips(): void
    {
        $agreement = JudgeAgreement::between(
            ['steady-street'],
            new EvalRunResults(3, [
                $this->clip('retired-clip', 'pass', [], 'pass', []),
                $this->clip('steady-street', 'error', [], 'pass', []),
            ]),
            new EvalRunResults(4, [
                $this->clip('retired-clip', 'pass', [], 'pass', []),
                $this->clip('steady-street', 'pass', [], 'pass', []),
            ]),
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertSame([
            ['id' => 'steady-street', 'reason' => 'error in run 3'],
        ], $agreement->clipsLeftOut);
    }

    public function test_an_unknown_verdict_is_left_out_as_an_error(): void
    {
        $agreement = JudgeAgreement::between(
            [],
            new EvalRunResults(3, [$this->clip('steady-street', 'maybe', [], 'pass', [])]),
            new EvalRunResults(4, [$this->clip('steady-street', 'pass', [], 'pass', [])]),
        );

        $this->assertSame(0, $agreement->compared);
        $this->assertSame([
            ['id' => 'steady-street', 'reason' => 'error in run 3'],
        ], $agreement->clipsLeftOut);
    }

    public function test_no_clips_to_compare_is_not_applicable(): void
    {
        $agreement = JudgeAgreement::between(
            [],
            new EvalRunResults(3, [$this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker'])]),
            new EvalRunResults(4, []),
        );

        $this->assertSame(0, $agreement->compared);
        $this->assertSame('n/a', $agreement->rate);
        $this->assertSame([
            ['id' => 'flicker-neon', 'reason' => 'error in run 3, not reached in run 4'],
        ], $agreement->clipsLeftOut);
        $this->assertSame([], $agreement->disagreements);
    }
}
