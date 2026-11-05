<?php

declare(strict_types=1);

return [
    'agent' => [
        'provider' => env('AGENT_PROVIDER', 'mock'),
        'model' => env('AGENT_MODEL', 'gemini-3.8-flash'),
    ],
    'judge' => [
        'provider' => env('JUDGE_PROVIDER', 'replay'),
        'model' => env('JUDGE_MODEL', 'gemini-3.5-flash-lite'),
        'gemini_model' => env('JUDGE_GEMINI_MODEL', env('JUDGE_MODEL', 'gemini-3.5-flash-lite')),
        'openai_model' => env('JUDGE_OPENAI_MODEL', 'gpt-5.4-mini'),
        'video_fps' => (int) env('JUDGE_VIDEO_FPS', 6),
        'max_frames' => (int) env('JUDGE_MAX_FRAMES', 48),
        'requests_per_minute' => (int) env('JUDGE_REQUESTS_PER_MINUTE', 10),
    ],
    'image' => env('IMAGE_PROVIDER', 'replay'),
    'video' => env('VIDEO_PROVIDER', 'replay'),
    'audio' => env('AUDIO_PROVIDER', 'replay'),
    'audio_requests_per_minute' => (int) env('AUDIO_REQUESTS_PER_MINUTE', 10),
    'replay_pending_polls' => (int) env('REPLAY_PENDING_POLLS', 1),
    'budget_cap' => (int) env('BUDGET_CAP', 100),
];
