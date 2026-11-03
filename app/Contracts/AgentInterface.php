<?php

declare(strict_types=1);

namespace App\Contracts;

interface AgentInterface
{
  /**
   * Plan 2-3 shots from the brief prompt.
   *
   * @return array<int, array{sequence: int, prompt: string}>
   */
  public function planShots(string $prompt): array;

  /**
   * Generate style anchor for the brief.
   */
  public function generateStyleAnchor(string $prompt): string;
}
