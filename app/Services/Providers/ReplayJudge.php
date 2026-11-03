<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;

class ReplayJudge implements JudgeInterface
{
  public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
  {
    return new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
      ],
      hardFailTags: [],
      reason: 'Replay verdict: image adheres to prompt and passes all rubric dimensions.',
      verdict: 'pass',
    );
  }

  public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
  {
    return new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
        'temporal_consistency' => 5,
      ],
      hardFailTags: [],
      reason: 'Replay verdict: video adheres to prompt with no temporal flicker.',
      verdict: 'pass',
    );
  }
}
