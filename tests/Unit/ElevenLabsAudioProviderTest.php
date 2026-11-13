<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AudioProviderInterface;
use App\DTOs\AudioResult;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Providers\ElevenLabsAudioProvider;
use App\Services\Providers\ReplayAudioProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ElevenLabsAudioProviderTest extends TestCase
{
    private const API_KEY = 'el-secret-key';

    private const VOICE_ID = '21m00Tcm4TlvDq8ikWAM';

    private const TTS_MODEL = 'eleven_v4_turbo';

    public function test_replay_stays_the_default_without_api_key(): void
    {
        config([
            'creative.audio' => 'replay',
            'services.elevenlabs.key' => null,
        ]);

        $this->assertInstanceOf(ReplayAudioProvider::class, app(AudioProviderInterface::class));
    }

    public function test_elevenlabs_audio_provider_resolves_from_container(): void
    {
        config([
            'creative.audio' => 'elevenlabs',
            'services.elevenlabs.key' => self::API_KEY,
            'services.elevenlabs.voice_id' => self::VOICE_ID,
            'services.elevenlabs.tts_model' => self::TTS_MODEL,
        ]);

        $this->assertInstanceOf(ElevenLabsAudioProvider::class, app(AudioProviderInterface::class));
    }

    public function test_elevenlabs_audio_provider_requires_api_key(): void
    {
        config([
            'creative.audio' => 'elevenlabs',
            'services.elevenlabs.key' => '  ',
            'services.elevenlabs.voice_id' => self::VOICE_ID,
            'services.elevenlabs.tts_model' => self::TTS_MODEL,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ELEVENLABS_API_KEY is required when AUDIO_PROVIDER=elevenlabs.');

        app(AudioProviderInterface::class);
    }

    public function test_elevenlabs_audio_provider_requires_voice_id_and_tts_model(): void
    {
        config([
            'creative.audio' => 'elevenlabs',
            'services.elevenlabs.key' => self::API_KEY,
            'services.elevenlabs.voice_id' => '',
            'services.elevenlabs.tts_model' => self::TTS_MODEL,
        ]);

        try {
            app(AudioProviderInterface::class);
            $this->fail('Expected InvalidArgumentException for blank voice_id.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ELEVENLABS_VOICE_ID', $exception->getMessage());
        }

        config([
            'creative.audio' => 'elevenlabs',
            'services.elevenlabs.key' => self::API_KEY,
            'services.elevenlabs.voice_id' => self::VOICE_ID,
            'services.elevenlabs.tts_model' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ELEVENLABS_TTS_MODEL is required when AUDIO_PROVIDER=elevenlabs.');

        app(AudioProviderInterface::class);
    }

    public function test_unknown_audio_provider_is_rejected(): void
    {
        config(['creative.audio' => 'gemini']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported audio provider [gemini].');

        app(AudioProviderInterface::class);
    }

    public function test_generate_calls_tts_first_then_sound_effects_and_returns_audio_result(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('VOICEOVER_AUDIO_BYTES', 200),
            'api.elevenlabs.io/v1/sound-generation' => Http::response('SFX_AUDIO_BYTES', 200),
        ]);

        $result = $this->provider()->generate('15s product teaser, rainy Tokyo, neon', 'Cinematic neon rain.');

        $this->assertInstanceOf(AudioResult::class, $result);
        $this->assertSame('VOICEOVER_AUDIO_BYTES', $result->voiceover);
        $this->assertSame('SFX_AUDIO_BYTES', $result->soundEffect);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        /** @var Request $ttsRequest */
        $ttsRequest = $recorded[0][0];
        $this->assertSame('POST', $ttsRequest->method());
        $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/'.self::VOICE_ID, $ttsRequest->url());
        $this->assertTrue($ttsRequest->hasHeader('xi-api-key', self::API_KEY));
        $this->assertSame([
            'text' => '15s product teaser, rainy Tokyo, neon',
            'model_id' => self::TTS_MODEL,
        ], $ttsRequest->data());

        /** @var Request $sfxRequest */
        $sfxRequest = $recorded[1][0];
        $this->assertSame('POST', $sfxRequest->method());
        $this->assertSame('https://api.elevenlabs.io/v1/sound-generation', $sfxRequest->url());
        $this->assertTrue($sfxRequest->hasHeader('xi-api-key', self::API_KEY));
        $this->assertArrayNotHasKey('duration_seconds', $sfxRequest->data());
        $this->assertSame(
            'Ambient soundscape matching Cinematic neon rain: 15s product teaser, rainy Tokyo, neon',
            $sfxRequest->data()['text'],
        );
    }

    public function test_generate_builds_ambient_prompt_without_style_anchor_when_blank(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('VOICEOVER', 200),
            'api.elevenlabs.io/v1/sound-generation' => Http::response('SFX', 200),
        ]);

        $this->provider()->generate('Rainy Tokyo street', '  ');

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('Ambient soundscape for: Rainy Tokyo street', $recorded[1][0]->data()['text']);
    }

    public function test_tts_rate_limit_or_error_never_calls_sound_effects(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response(['detail' => self::API_KEY], 429),
            'api.elevenlabs.io/v1/sound-generation' => Http::response('SFX', 200),
        ]);

        try {
            $this->provider()->generate('15s product teaser');
            $this->fail('A 429 from TTS should throw ProviderRateLimitedException.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('ElevenLabs TTS returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_tts_http_error_never_calls_sound_effects_or_leaks_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response(self::API_KEY, 500),
            'api.elevenlabs.io/v1/sound-generation' => Http::response('SFX', 200),
        ]);

        try {
            $this->provider()->generate('15s product teaser');
            $this->fail('A 500 from TTS should throw RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ElevenLabs TTS returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_empty_or_non_audio_tts_body_never_calls_sound_effects(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::sequence()
                ->push('', 200)
                ->push(['detail' => 'unexpected'], 200)
                ->push('<html>error</html>', 200, ['Content-Type' => 'text/html']),
            'api.elevenlabs.io/v1/sound-generation' => Http::response('SFX', 200),
        ]);

        foreach (['empty', 'json', 'html'] as $index => $case) {
            try {
                $this->provider()->generate('15s product teaser');
                $this->fail("Expected RuntimeException for {$case} TTS body.");
            } catch (RuntimeException $exception) {
                $this->assertSame('ElevenLabs TTS returned an invalid or empty audio payload.', $exception->getMessage(), $case);
            }

            $this->assertCount($index + 1, Http::recorded(), $case);
        }
    }

    public function test_sound_effects_rate_limit_and_http_error_handling(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('VOICEOVER', 200),
            'api.elevenlabs.io/v1/sound-generation' => Http::sequence()
                ->push(['detail' => self::API_KEY], 429)
                ->push(self::API_KEY, 502)
                ->push('', 200)
                ->push(['detail' => 'unexpected'], 200),
        ]);

        try {
            $this->provider()->generate('15s product teaser');
            $this->fail('A 429 from Sound Effects should throw ProviderRateLimitedException.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('ElevenLabs sound effects returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        try {
            $this->provider()->generate('15s product teaser');
            $this->fail('A 502 from Sound Effects should throw RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ElevenLabs sound effects returned HTTP 502.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        foreach (['empty', 'json'] as $case) {
            try {
                $this->provider()->generate('15s product teaser');
                $this->fail("Expected RuntimeException for {$case} Sound Effects body.");
            } catch (RuntimeException $exception) {
                $this->assertSame('ElevenLabs sound effects returned an invalid or empty audio payload.', $exception->getMessage(), $case);
            }
        }
    }

    private function provider(): ElevenLabsAudioProvider
    {
        return new ElevenLabsAudioProvider(self::API_KEY, self::VOICE_ID, self::TTS_MODEL);
    }
}
