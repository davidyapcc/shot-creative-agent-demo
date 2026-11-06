<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JudgeInterface;
use App\Services\Judge\JudgeHttp;
use App\Services\Judge\JudgeMedia;
use App\Services\Judge\JudgeRubric;
use App\Services\Providers\GeminiJudge;
use App\Services\Providers\OpenAiJudge;
use App\Services\Providers\ReplayJudge;
use InvalidArgumentException;

class JudgeFactory
{
    public function make(string $driver, ?string $replayFixture = null): JudgeInterface
    {
        return match ($driver) {
            'replay' => new ReplayJudge($replayFixture),
            'gemini' => $this->geminiJudge(),
            'openai' => $this->openAiJudge(),
            default => throw new InvalidArgumentException("Unsupported judge provider [{$driver}]."),
        };
    }

    private function geminiJudge(): GeminiJudge
    {
        return new GeminiJudge(
            apiKey: $this->judgeKey('services.gemini.key', 'GEMINI_API_KEY', 'gemini'),
            model: $this->judgeModel('creative.judge.gemini_model', 'JUDGE_GEMINI_MODEL'),
            videoFps: $this->judgeVideoFps(),
            rubric: new JudgeRubric,
            media: new JudgeMedia,
            http: new JudgeHttp,
        );
    }

    private function openAiJudge(): OpenAiJudge
    {
        return new OpenAiJudge(
            apiKey: $this->judgeKey('services.openai.key', 'OPENAI_API_KEY', 'openai'),
            model: $this->judgeModel('creative.judge.openai_model', 'JUDGE_OPENAI_MODEL'),
            videoFps: $this->judgeVideoFps(),
            maxFrames: $this->judgeMaxFrames(),
            rubric: new JudgeRubric,
            media: new JudgeMedia,
            http: new JudgeHttp,
        );
    }

    private function judgeKey(string $configKey, string $envName, string $provider): string
    {
        $key = config($configKey);

        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException("{$envName} is required when JUDGE_PROVIDER={$provider}.");
        }

        return trim($key);
    }

    private function judgeModel(string $configKey, string $envName): string
    {
        $model = config($configKey);

        if (! is_string($model) || $model === '') {
            throw new InvalidArgumentException("{$envName} is required for the selected judge provider.");
        }

        return $model;
    }

    private function judgeVideoFps(): int
    {
        $fps = (int) config('creative.judge.video_fps');

        if ($fps < 4 || $fps > 8) {
            throw new InvalidArgumentException("JUDGE_VIDEO_FPS must be from 4 to 8, [{$fps}] given.");
        }

        return $fps;
    }

    private function judgeMaxFrames(): int
    {
        $maxFrames = (int) config('creative.judge.max_frames');

        if ($maxFrames < 1) {
            throw new InvalidArgumentException("JUDGE_MAX_FRAMES must be at least 1, [{$maxFrames}] given.");
        }

        return $maxFrames;
    }
}
