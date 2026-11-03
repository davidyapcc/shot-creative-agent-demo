<?php

declare(strict_types=1);

namespace App\DTOs;

class JudgementResult
{
  /**
   * @param array<string, int> $scores
   * @param array<string> $hardFailTags
   */
  public function __construct(
    public readonly array $scores,
    public readonly array $hardFailTags = [],
    public readonly ?string $reason = null,
    public readonly ?string $verdict = null,
  )
  {
  }

  public function verdict(): string
  {
    if ($this->verdict !== null)
    {
      return $this->verdict;
    }

    if (!empty($this->hardFailTags))
    {
      return 'fail';
    }

    foreach ($this->scores as $score)
    {
      if ($score < 3)
      {
        return 'fail';
      }
    }

    return 'pass';
  }

  public function isPass(): bool
  {
    return $this->verdict() === 'pass';
  }

  public function isFail(): bool
  {
    return !$this->isPass();
  }
}
