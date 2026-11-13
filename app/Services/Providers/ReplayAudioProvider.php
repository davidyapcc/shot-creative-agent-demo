<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\AudioProviderInterface;
use App\DTOs\AudioResult;
use LogicException;

class ReplayAudioProvider implements AudioProviderInterface
{
    public const VOICEOVER_FIXTURE_PATH = 'fixtures/replay/voiceover.wav';

    public const SOUND_EFFECT_FIXTURE_PATH = 'fixtures/replay/sfx.wav';

    /**
     * Minimal valid PCM WAV fixtures. The two payloads differ so a replay
     * returns a voiceover and a separate sound effect.
     */
    private const VOICEOVER_WAV_BASE64 = 'UklGRjQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YRAAAAAAAQACAAMAAgABAAAAAQAC';

    private const SOUND_EFFECT_WAV_BASE64 = 'UklGRiwAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQgAAAAAEAAAACAAAA==';

    private readonly string $voiceoverFixturePath;

    private readonly string $soundEffectFixturePath;

    public function __construct(?string $voiceoverFixturePath = null, ?string $soundEffectFixturePath = null)
    {
        $this->voiceoverFixturePath = $voiceoverFixturePath ?? base_path(self::VOICEOVER_FIXTURE_PATH);
        $this->soundEffectFixturePath = $soundEffectFixturePath ?? base_path(self::SOUND_EFFECT_FIXTURE_PATH);
    }

    public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
    {
        return new AudioResult(
            voiceover: $this->readFixture($this->voiceoverFixturePath, self::VOICEOVER_WAV_BASE64, 'voiceover'),
            soundEffect: $this->readFixture($this->soundEffectFixturePath, self::SOUND_EFFECT_WAV_BASE64, 'sound effect'),
        );
    }

    private function readFixture(string $path, string $fallbackBase64, string $label): string
    {
        if (is_file($path)) {
            $bytes = file_get_contents($path);

            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        }

        return $this->decodeFixture($fallbackBase64, $label);
    }

    private function decodeFixture(string $base64, string $label): string
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new LogicException("Replay {$label} fixture is not valid base64.");
        }

        return $bytes;
    }
}
