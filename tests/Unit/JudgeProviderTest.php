<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JudgeInterface;
use App\Services\Providers\GeminiJudge;
use App\Services\Providers\OpenAiJudge;
use App\Services\Providers\ReplayJudge;
use InvalidArgumentException;
use Tests\TestCase;

class JudgeProviderTest extends TestCase
{
    public function test_replay_stays_the_default_without_api_keys(): void
    {
        config([
            'creative.judge.provider' => 'replay',
            'services.gemini.key' => null,
            'services.openai.key' => null,
        ]);

        $this->assertInstanceOf(ReplayJudge::class, app(JudgeInterface::class));
    }

    public function test_gemini_and_openai_resolve_their_drivers(): void
    {
        config([
            'creative.judge.provider' => 'gemini',
            'creative.judge.video_fps' => 6,
            'services.gemini.key' => 'gemini-key',
        ]);
        $this->assertInstanceOf(GeminiJudge::class, app(JudgeInterface::class));

        config([
            'creative.judge.provider' => 'openai',
            'creative.judge.video_fps' => 6,
            'creative.judge.max_frames' => 24,
            'services.openai.key' => 'openai-key',
        ]);
        $this->assertInstanceOf(OpenAiJudge::class, app(JudgeInterface::class));
    }

    public function test_unknown_judge_provider_is_rejected(): void
    {
        config(['creative.judge.provider' => 'claude']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported judge provider [claude].');

        app(JudgeInterface::class);
    }

    public function test_gemini_without_a_key_fails_fast(): void
    {
        config([
            'creative.judge.provider' => 'gemini',
            'services.gemini.key' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY is required when JUDGE_PROVIDER=gemini.');

        app(JudgeInterface::class);
    }

    public function test_openai_without_a_key_fails_fast(): void
    {
        config([
            'creative.judge.provider' => 'openai',
            'services.openai.key' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY is required when JUDGE_PROVIDER=openai.');

        app(JudgeInterface::class);
    }

    public function test_video_fps_outside_four_to_eight_fails_fast(): void
    {
        config([
            'creative.judge.provider' => 'gemini',
            'creative.judge.video_fps' => 1,
            'services.gemini.key' => 'gemini-key',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JUDGE_VIDEO_FPS must be from 4 to 8');

        app(JudgeInterface::class);
    }

    public function test_frame_cap_below_one_fails_fast(): void
    {
        config([
            'creative.judge.provider' => 'openai',
            'creative.judge.video_fps' => 6,
            'creative.judge.max_frames' => 0,
            'services.openai.key' => 'openai-key',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JUDGE_MAX_FRAMES must be at least 1');

        app(JudgeInterface::class);
    }
}
