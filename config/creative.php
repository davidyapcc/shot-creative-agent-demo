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
  ],
  'image' => env('IMAGE_PROVIDER', 'replay'),
  'video' => env('VIDEO_PROVIDER', 'replay'),
  'audio' => env('AUDIO_PROVIDER', 'replay'),
  'replay_pending_polls' => env('REPLAY_PENDING_POLLS') !== null ? (int) env('REPLAY_PENDING_POLLS') : null,
];
