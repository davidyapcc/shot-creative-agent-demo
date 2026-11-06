<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JudgeFactory;
use App\Services\Providers\GeminiJudge;
use App\Services\Providers\OpenAiJudge;
use App\Services\Providers\ReplayJudge;
use Tests\TestCase;

class JudgeFactoryTest extends TestCase
{
    public function test_replay_is_built_without_api_keys(): void
    {
        config([
            'services.gemini.key' => null,
            'services.openai.key' => null,
        ]);

        $this->assertInstanceOf(ReplayJudge::class, app(JudgeFactory::class)->make('replay'));
    }

    public function test_named_drivers_build_gemini_and_openai(): void
    {
        config([
            'creative.judge.video_fps' => 6,
            'creative.judge.max_frames' => 24,
            'services.gemini.key' => 'gemini-key',
            'services.openai.key' => 'openai-key',
        ]);

        $factory = app(JudgeFactory::class);

        $this->assertInstanceOf(GeminiJudge::class, $factory->make('gemini'));
        $this->assertInstanceOf(OpenAiJudge::class, $factory->make('openai'));
    }

    public function test_replay_can_read_an_eval_fixture(): void
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

        $judge = app(JudgeFactory::class)->make('replay', $path);

        try {
            $verdict = $judge->judgeVideo('evals/golden/warp-car.mp4', 'A car keeps its shape.');
        } finally {
            unlink($path);
        }

        $this->assertSame('Placeholder pass.', $verdict->reason);
    }
}
