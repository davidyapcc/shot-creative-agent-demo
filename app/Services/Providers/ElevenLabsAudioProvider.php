<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\AudioProviderInterface;
use App\DTOs\AudioResult;
use App\Exceptions\ProviderRateLimitedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class ElevenLabsAudioProvider implements AudioProviderInterface
{
    private const TIMEOUT_SECONDS = 45;

    private const DEFAULT_VOICE_ID = '21m00Tcm4TlvDq8ikWAM';

    private const DEFAULT_TTS_MODEL = 'eleven_v4_turbo';

    private readonly string $apiKey;

    private readonly string $voiceId;

    private readonly string $ttsModel;

    public function __construct(
        string $apiKey,
        string $voiceId = self::DEFAULT_VOICE_ID,
        string $ttsModel = self::DEFAULT_TTS_MODEL,
    ) {
        $this->apiKey = self::requireNonEmpty($apiKey, 'ELEVENLABS_API_KEY is required when AUDIO_PROVIDER=elevenlabs.');
        $this->voiceId = self::requireNonEmpty($voiceId, 'ELEVENLABS_VOICE_ID is required when AUDIO_PROVIDER=elevenlabs.');
        $this->ttsModel = self::requireNonEmpty($ttsModel, 'ELEVENLABS_TTS_MODEL is required when AUDIO_PROVIDER=elevenlabs.');
    }

    public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
    {
        // TTS runs first so a 429 or error never spends one of the 8 monthly sound-effect calls.
        $voiceover = $this->postBinary(
            'ElevenLabs TTS',
            'https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($this->voiceId),
            [
                'text' => $prompt,
                'model_id' => $this->ttsModel,
            ],
        );

        $soundEffect = $this->postBinary(
            'ElevenLabs sound effects',
            'https://api.elevenlabs.io/v1/sound-generation',
            [
                'text' => $this->ambientPrompt($prompt, $styleAnchor),
            ],
        );

        return new AudioResult(
            voiceover: $voiceover,
            soundEffect: $soundEffect,
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function postBinary(string $label, string $url, array $payload): string
    {
        $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($url, $payload);

        if ($response->status() === 429) {
            throw new ProviderRateLimitedException("{$label} returned HTTP 429.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$label} returned HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (! $this->isBinaryAudio($response, $body)) {
            throw new RuntimeException("{$label} returned an invalid or empty audio payload.");
        }

        return $body;
    }

    private function isBinaryAudio(Response $response, string $body): bool
    {
        $trimmed = ltrim($body);

        if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[') || str_starts_with($trimmed, '<')) {
            return false;
        }

        $contentType = strtolower($response->header('Content-Type'));

        if (str_starts_with($contentType, 'application/json') || str_starts_with($contentType, 'text/')) {
            return false;
        }

        return true;
    }

    private function ambientPrompt(string $prompt, ?string $styleAnchor): string
    {
        if (! is_string($styleAnchor) || trim($styleAnchor) === '') {
            return "Ambient soundscape for: {$prompt}";
        }

        $anchor = rtrim(trim($styleAnchor), '.');

        return "Ambient soundscape matching {$anchor}: {$prompt}";
    }

    private static function requireNonEmpty(string $value, string $message): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException($message);
        }

        return $trimmed;
    }
}
