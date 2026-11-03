<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;

class JudgementResult
{
  public const MIN_PASSING_SCORE = 3;

  /**
   * @param array<string, int> $scores
   * @param array<string> $hardFailTags
   */
  public function __construct(
    public readonly array $scores,
    public readonly array $hardFailTags = [],
    public readonly ?string $reason = null,
    public readonly ?JudgementVerdict $verdict = null,
  )
  {
  }

  public function verdict(): JudgementVerdict
  {
    if ($this->verdict !== null)
    {
      return $this->verdict;
    }

    if (!empty($this->hardFailTags))
    {
      return JudgementVerdict::Fail;
    }

    foreach ($this->scores as $score)
    {
      if ($score < self::MIN_PASSING_SCORE)
      {
        return JudgementVerdict::Fail;
      }
    }

    return JudgementVerdict::Pass;
  }

  public function isPass(): bool
  {
    return $this->verdict() === JudgementVerdict::Pass;
  }

  public function isFail(): bool
  {
    return !$this->isPass();
  }

  /**
   * Convert to database attribute array for Judgement model.
   *
   * @return array<string, mixed>
   */
  public function toModelAttributes(int $shotId, JudgementStage $stage): array
  {
    return [
      'shot_id' => $shotId,
      'stage' => $stage->value,
      'scores' => $this->scores,
      'hard_fail_tags' => $this->hardFailTags,
      'reason' => $this->reason,
      'verdict' => $this->verdict()->value,
    ];
  }
}
