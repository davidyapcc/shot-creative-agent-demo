<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ImageProviderInterface;

class ReplayImageProvider implements ImageProviderInterface
{
    public const FIXTURE_PATH = 'fixtures/replay/keyframe.png';

    /**
     * Minimal valid 1x1 PNG bytes as default fixture.
     */
    private const DEFAULT_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private readonly string $fixturePath;

    public function __construct(?string $fixturePath = null)
    {
        $this->fixturePath = $fixturePath ?? base_path(self::FIXTURE_PATH);
    }

    public function generateImage(string $prompt, ?string $styleAnchor = null): string
    {
        if (is_file($this->fixturePath)) {
            $bytes = file_get_contents($this->fixturePath);

            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        }

        return base64_decode(self::DEFAULT_PNG_BASE64, true) ?: '';
    }
}
