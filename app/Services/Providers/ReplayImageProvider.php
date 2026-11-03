<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ImageProviderInterface;

class ReplayImageProvider implements ImageProviderInterface
{
  /**
   * Minimal valid 1x1 PNG bytes as default fixture.
   */
  private const DEFAULT_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

  public function generateImage(string $prompt, ?string $styleAnchor = null): string
  {
    return base64_decode(self::DEFAULT_PNG_BASE64, true) ?: '';
  }
}
