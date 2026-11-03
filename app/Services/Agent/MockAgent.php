<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Contracts\AgentInterface;

class MockAgent implements AgentInterface
{
  /**
   * Plan 2-3 shots deterministically based on prompt.
   *
   * @return array<int, array{sequence: int, prompt: string}>
   */
  public function planShots(string $prompt): array
  {
    return [
      [
        'sequence' => 1,
        'prompt' => "Shot 1: Wide establishing shot setting the scene. {$prompt}",
      ],
      [
        'sequence' => 2,
        'prompt' => "Shot 2: Medium shot focusing on the central subject and atmosphere. {$prompt}",
      ],
      [
        'sequence' => 3,
        'prompt' => "Shot 3: Dynamic close-up highlighting key details and resolution. {$prompt}",
      ],
    ];
  }

  public function generateStyleAnchor(string $prompt): string
  {
    return "Consistent cinematic style, high dynamic range, matching color palette for: {$prompt}";
  }
}
