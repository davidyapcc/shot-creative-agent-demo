<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JudgeAgreement;
use Tests\TestCase;

class JudgeAgreementTest extends TestCase
{
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
            3,
            [
                ...$shared,
                $this->clip('warp-car', 'pass', [], 'fail', ['object_warp']),
            ],
            4,
            [
                ...$shared,
                $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
            ],
        );

        $this->assertSame(6, $agreement->compared);
        $this->assertEqualsWithDelta(5 / 6, $agreement->rate, 0.0000001);
        $this->assertSame([], $agreement->leftOut);
        $this->assertSame([
            [
                'id' => 'warp-car',
                'left_verdict' => 'pass',
                'left_tags' => [],
                'right_verdict' => 'fail',
                'right_tags' => ['object_warp'],
                'human_verdict' => 'fail',
                'human_tags' => ['object_warp'],
            ],
        ], $agreement->disagreements);
    }

    public function test_the_same_verdict_with_different_tags_counts_as_agreement(): void
    {
        $agreement = JudgeAgreement::between(
            1,
            [$this->clip('text-marquee', 'fail', ['text_artifact'], 'fail', ['text_artifact'])],
            2,
            [$this->clip('text-marquee', 'fail', ['object_warp'], 'fail', ['text_artifact'])],
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertEqualsWithDelta(1, $agreement->rate, 0.0000001);
        $this->assertSame([], $agreement->disagreements);
        $this->assertSame([], $agreement->leftOut);
    }

    public function test_an_error_or_a_clip_never_reached_is_left_out_of_the_rate(): void
    {
        $agreement = JudgeAgreement::between(
            3,
            [
                $this->clip('steady-street', 'pass', [], 'pass', []),
                $this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker']),
                $this->clip('warp-car', 'fail', ['object_warp'], 'fail', ['object_warp']),
            ],
            4,
            [
                $this->clip('steady-street', 'fail', [], 'pass', []),
                $this->clip('flicker-neon', 'fail', ['temporal_flicker'], 'fail', ['temporal_flicker']),
                $this->clip('text-marquee', 'pass', [], 'fail', ['text_artifact']),
            ],
        );

        $this->assertSame(1, $agreement->compared);
        $this->assertEqualsWithDelta(0, $agreement->rate, 0.0000001);
        $this->assertSame([
            ['id' => 'flicker-neon', 'reason' => 'error in run 3'],
            ['id' => 'warp-car', 'reason' => 'not reached in run 4'],
            ['id' => 'text-marquee', 'reason' => 'not reached in run 3'],
        ], $agreement->leftOut);
        $this->assertSame([
            [
                'id' => 'steady-street',
                'left_verdict' => 'pass',
                'left_tags' => [],
                'right_verdict' => 'fail',
                'right_tags' => [],
                'human_verdict' => 'pass',
                'human_tags' => [],
            ],
        ], $agreement->disagreements);
    }

    public function test_no_clips_to_compare_is_not_applicable(): void
    {
        $agreement = JudgeAgreement::between(
            3,
            [$this->clip('flicker-neon', 'error', [], 'fail', ['temporal_flicker'])],
            4,
            [],
        );

        $this->assertSame(0, $agreement->compared);
        $this->assertSame('n/a', $agreement->rate);
        $this->assertSame([
            ['id' => 'flicker-neon', 'reason' => 'error in run 3, not reached in run 4'],
        ], $agreement->leftOut);
        $this->assertSame([], $agreement->disagreements);
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $humanTags
     * @return array<string, mixed>
     */
    private function clip(string $id, string $verdict, array $tags, string $humanVerdict, array $humanTags): array
    {
        return [
            'id' => $id,
            'verdict' => $verdict,
            'tags' => $tags,
            'human' => [
                'verdict' => $humanVerdict,
                'tags' => $humanTags,
            ],
        ];
    }
}
