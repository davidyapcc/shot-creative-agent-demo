<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Providers\ReplayJudge;
use RuntimeException;
use Tests\TestCase;

class ReplayEvalJudgeTest extends TestCase
{
    public function test_eval_fixture_returns_the_recorded_verdict_for_a_clip_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eval-judge');
        file_put_contents($path, json_encode([
            'placeholder' => true,
            'clips' => [
                'evals/golden/warp-car.mp4' => [
                    'scores' => [
                        'prompt_adherence' => 5,
                        'anatomy' => 5,
                        'object_integrity' => 5,
                        'temporal_consistency' => 5,
                    ],
                    'hard_fail_tags' => [],
                    'reason' => 'Placeholder pass.',
                ],
            ],
        ]));

        $judge = new ReplayJudge($path);

        try {
            $verdict = $judge->judgeVideo('evals/golden/warp-car.mp4', 'A car keeps its shape.');
        } finally {
            unlink($path);
        }

        $this->assertTrue($verdict->isPass());
        $this->assertSame([], $verdict->hardFailTags);
        $this->assertSame('Placeholder pass.', $verdict->reason);
    }

    public function test_missing_eval_clip_fails_fast_with_its_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eval-judge');
        file_put_contents($path, json_encode([
            'placeholder' => true,
            'clips' => [],
        ]));

        $judge = new ReplayJudge($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evals/golden/missing.mp4');

        try {
            $judge->judgeVideo('evals/golden/missing.mp4', 'A missing clip.');
        } finally {
            unlink($path);
        }
    }
}
