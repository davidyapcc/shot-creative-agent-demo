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

  /**
   * Rewrite a failed prompt. Preserve the scene, simplify only the failing
   * element, and do not repeat a fix that already failed.
   *
   * @param array{
   *   brief: string,
   *   original_prompt: string,
   *   failed_scores: array<string, int>,
   *   reason: string|null,
   *   previous_attempts: list<array{prompt: string, reason: string|null}>
   * } $context
   */
  public function rewritePrompt(array $context): string;
}
