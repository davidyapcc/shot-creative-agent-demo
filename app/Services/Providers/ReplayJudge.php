<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Enums\JudgementVerdict;

class ReplayJudge implements JudgeInterface
{
  public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
  {
    $failure = $this->scriptedImageFailure($prompt);

    if ($failure !== null)
    {
      return $failure;
    }

    return new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
      ],
      hardFailTags: [],
      reason: 'Replay verdict: image adheres to prompt and passes all rubric dimensions.',
      verdict: JudgementVerdict::Pass,
    );
  }

  private function scriptedImageFailure(string $prompt): ?JudgementResult
  {
    $path = base_path('fixtures/replay/judge.json');
    $decoded = json_decode((string) file_get_contents($path), true);
    $image = is_array($decoded) ? ($decoded['image'] ?? null) : null;

    if (!is_array($image))
    {
      return null;
    }

    $prefix = (string) ($image['fail_when_prompt_starts_with'] ?? '');
    $marker = (string) ($image['pass_when_prompt_contains'] ?? '');
    $failure = $image['failure'] ?? null;

    if ($prefix === '' || !is_array($failure) || !str_starts_with($prompt, $prefix))
    {
      return null;
    }

    if ($marker !== '' && str_contains($prompt, $marker))
    {
      return null;
    }

    return new JudgementResult(
      scores: $failure['scores'],
      hardFailTags: $failure['hard_fail_tags'],
      reason: $failure['reason'],
      verdict: JudgementVerdict::from($failure['verdict']),
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
      verdict: JudgementVerdict::Pass,
    );
  }
}
