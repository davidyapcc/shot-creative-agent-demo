<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\JudgementResult;

interface JudgeInterface
{
  /**
   * Judge a keyframe image against the rubric.
   */
  public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult;

  /**
   * Judge a video clip against the rubric.
   */
  public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult;
}
