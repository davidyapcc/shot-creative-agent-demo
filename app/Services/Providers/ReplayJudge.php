<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use RuntimeException;

class ReplayJudge implements JudgeInterface
{
  private const FIXTURE_PATH = 'fixtures/replay/judge.json';

  private readonly string $fixturePath;

  public function __construct(?string $fixturePath = null)
  {
    $this->fixturePath = $fixturePath ?? base_path(self::FIXTURE_PATH);
  }

  public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
  {
    return $this->scriptedFailure('image', $prompt) ?? new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
      ],
      hardFailTags: [],
      reason: 'Replay verdict: image adheres to prompt and passes all rubric dimensions.',
    );
  }

  public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
  {
    return $this->scriptedFailure('video', $prompt) ?? new JudgementResult(
      scores: [
        'prompt_adherence' => 5,
        'anatomy' => 5,
        'object_integrity' => 5,
        'temporal_consistency' => 5,
      ],
      hardFailTags: [],
      reason: 'Replay verdict: video adheres to prompt with no temporal flicker.',
    );
  }

  /**
   * The fixture fails a step once for prompts with the given prefix, and
   * passes the rewrite, which carries the agent's simplify marker.
   */
  private function scriptedFailure(string $step, string $prompt): ?JudgementResult
  {
    $script = $this->fixture()[$step] ?? null;

    if ($script === null)
    {
      return null;
    }

    $prefix = $script['fail_when_prompt_starts_with'] ?? null;
    $marker = $script['pass_when_prompt_contains'] ?? null;
    $failure = $script['failure'] ?? null;

    if (!is_string($prefix) || $prefix === '' || !is_string($marker) || !$this->isValidFailure($failure))
    {
      throw new RuntimeException("Replay judge fixture has a malformed [{$step}] entry: {$this->fixturePath}");
    }

    if (!str_starts_with($prompt, $prefix) || str_contains($prompt, $marker))
    {
      return null;
    }

    return new JudgementResult(
      scores: $failure['scores'],
      hardFailTags: $failure['hard_fail_tags'],
      reason: $failure['reason'],
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function fixture(): array
  {
    $contents = is_file($this->fixturePath) ? file_get_contents($this->fixturePath) : false;

    if ($contents === false)
    {
      throw new RuntimeException("Replay judge fixture is missing: {$this->fixturePath}");
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded))
    {
      throw new RuntimeException("Replay judge fixture is not valid JSON: {$this->fixturePath}");
    }

    return $decoded;
  }

  private function isValidFailure(mixed $failure): bool
  {
    return is_array($failure)
      && is_array($failure['scores'] ?? null)
      && is_array($failure['hard_fail_tags'] ?? null)
      && is_string($failure['reason'] ?? null);
  }
}
