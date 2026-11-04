<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\AudioProviderInterface;
use App\DTOs\AudioResult;

class ReplayAudioProvider implements AudioProviderInterface
{
  /**
   * Minimal valid PCM WAV fixtures. The two payloads differ so a replay
   * returns a voiceover and a separate sound effect.
   */
  private const VOICEOVER_WAV_BASE64 = 'UklGRjQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YRAAAAAAAQACAAMAAgABAAAAAQAC';

  private const SOUND_EFFECT_WAV_BASE64 = 'UklGRiwAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQgAAAAAEAAAACAAAA==';

  public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
  {
    return new AudioResult(
      voiceover: base64_decode(self::VOICEOVER_WAV_BASE64, true) ?: '',
      soundEffect: base64_decode(self::SOUND_EFFECT_WAV_BASE64, true) ?: '',
    );
  }
}
