<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\VideoRenderResult;

interface VideoProviderInterface
{
  /**
   * Submit an image-to-video render job.
   *
   * @param string $prompt
   * @param string $keyframePath
   * @param array<string, mixed> $options
   * @return string Unique render identifier
   */
  public function submit(string $prompt, string $keyframePath, array $options = []): string;

  /**
   * Poll status of submitted render job.
   */
  public function poll(string $renderId): VideoRenderResult;
}
