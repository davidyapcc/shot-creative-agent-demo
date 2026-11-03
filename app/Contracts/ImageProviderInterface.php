<?php

declare(strict_types=1);

namespace App\Contracts;

interface ImageProviderInterface
{
  /**
   * Generate an image from a prompt and optional style anchor.
   *
   * @return string Binary image data (e.g. PNG).
   */
  public function generateImage(string $prompt, ?string $styleAnchor = null): string;
}
