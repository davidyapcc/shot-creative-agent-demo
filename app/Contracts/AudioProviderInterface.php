<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AudioResult;

interface AudioProviderInterface
{
  /**
   * Generate one voiceover and one sound effect for a brief.
   */
  public function generate(string $prompt, ?string $styleAnchor = null): AudioResult;
}
