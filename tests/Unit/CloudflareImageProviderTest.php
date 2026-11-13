<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Providers\CloudflareImageProvider;
use App\Services\Providers\ReplayImageProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CloudflareImageProviderTest extends TestCase
{
    private const ACCOUNT_ID = 'cf-account-123';

    private const API_TOKEN = 'cf-secret-token';

    private const MODEL = '@cf/black-forest-labs/flux-1-schnell';

    public function test_replay_stays_the_default_without_api_keys(): void
    {
        config([
            'creative.image' => 'replay',
            'services.cloudflare.account_id' => null,
            'services.cloudflare.key' => null,
        ]);

        $this->assertInstanceOf(ReplayImageProvider::class, app(ImageProviderInterface::class));
    }

    public function test_cloudflare_image_provider_resolves_from_container(): void
    {
        config([
            'creative.image' => 'cloudflare',
            'creative.image_model' => self::MODEL,
            'services.cloudflare.account_id' => self::ACCOUNT_ID,
            'services.cloudflare.key' => self::API_TOKEN,
        ]);

        $this->assertInstanceOf(CloudflareImageProvider::class, app(ImageProviderInterface::class));
    }

    public function test_cloudflare_image_provider_requires_account_id(): void
    {
        config([
            'creative.image' => 'cloudflare',
            'creative.image_model' => self::MODEL,
            'services.cloudflare.account_id' => '   ',
            'services.cloudflare.key' => self::API_TOKEN,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CLOUDFLARE_ACCOUNT_ID is required when IMAGE_PROVIDER=cloudflare.');

        app(ImageProviderInterface::class);
    }

    public function test_cloudflare_image_provider_requires_api_token(): void
    {
        config([
            'creative.image' => 'cloudflare',
            'creative.image_model' => self::MODEL,
            'services.cloudflare.account_id' => self::ACCOUNT_ID,
            'services.cloudflare.key' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CLOUDFLARE_API_TOKEN is required when IMAGE_PROVIDER=cloudflare.');

        app(ImageProviderInterface::class);
    }

    public function test_cloudflare_image_provider_requires_model(): void
    {
        config([
            'creative.image' => 'cloudflare',
            'creative.image_model' => '',
            'services.cloudflare.account_id' => self::ACCOUNT_ID,
            'services.cloudflare.key' => self::API_TOKEN,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IMAGE_MODEL is required when IMAGE_PROVIDER=cloudflare.');

        app(ImageProviderInterface::class);
    }

    public function test_unknown_image_provider_is_rejected(): void
    {
        config(['creative.image' => 'openai']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image provider [openai].');

        app(ImageProviderInterface::class);
    }

    public function test_generate_image_composes_style_anchor_and_decodes_json_base64_png(): void
    {
        $pngBytes = "\x89PNG\r\n\x1a\n".'fake-flux-png';

        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'result' => [
                    'image' => base64_encode($pngBytes),
                ],
                'success' => true,
            ]),
        ]);

        $result = $this->provider()->generateImage('Shot 1: neon alley', 'Cinematic neon rain.');

        $this->assertSame($pngBytes, $result);

        $request = $this->request();
        $this->assertSame('POST', $request->method());
        $this->assertSame(
            'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/ai/run/'.self::MODEL,
            $request->url(),
        );
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::API_TOKEN));
        $this->assertSame('Cinematic neon rain. Shot 1: neon alley', $request->data()['prompt']);
    }

    public function test_generate_image_sends_prompt_alone_when_style_anchor_is_blank(): void
    {
        $jpegBytes = "\xFF\xD8\xFF".'fake-flux-jpeg';

        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'result' => [
                    'image' => base64_encode($jpegBytes),
                ],
            ]),
        ]);

        $result = $this->provider()->generateImage('Shot 1: neon alley', '   ');

        $this->assertSame($jpegBytes, $result);
        $this->assertSame('Shot 1: neon alley', $this->request()->data()['prompt']);
    }

    public function test_generate_image_accepts_raw_png_and_jpeg_binary_responses(): void
    {
        $pngBytes = "\x89PNG\r\n\x1a\n".'raw-png';
        $jpegBytes = "\xFF\xD8\xFF".'raw-jpeg';

        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::sequence()
                ->push($pngBytes, 200, ['Content-Type' => 'image/png'])
                ->push($jpegBytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->assertSame($pngBytes, $this->provider()->generateImage('Shot 1'));
        $this->assertSame($jpegBytes, $this->provider()->generateImage('Shot 2'));
    }

    public function test_generate_image_rejects_corrupt_or_non_image_payloads(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::sequence()
                ->push('', 200)
                ->push(['result' => ['image' => 'not-valid-base64!!!']], 200)
                ->push(['result' => ['image' => base64_encode('NOT_AN_IMAGE')]], 200)
                ->push('plain text error body', 200),
        ]);

        foreach (['empty', 'invalid base64', 'non-image bytes', 'plain text'] as $case) {
            try {
                $this->provider()->generateImage('Shot 1');
                $this->fail("Expected RuntimeException for {$case} payload.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('invalid or corrupt image', $exception->getMessage(), $case);
            }
        }
    }

    public function test_rate_limit_raises_provider_rate_limited_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['errors' => [self::API_TOKEN]], 429),
        ]);

        try {
            $this->provider()->generateImage('Shot 1');
            $this->fail('A 429 should be retryable.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('Cloudflare image provider returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_TOKEN, $exception->getMessage());
        }
    }

    public function test_other_http_errors_fail_without_exposing_the_api_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/*' => Http::response(self::API_TOKEN, 500),
        ]);

        try {
            $this->provider()->generateImage('Shot 1');
            $this->fail('A provider error should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloudflare image provider returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_TOKEN, $exception->getMessage());
        }
    }

    private function provider(): CloudflareImageProvider
    {
        return new CloudflareImageProvider(self::ACCOUNT_ID, self::API_TOKEN, self::MODEL);
    }

    private function request(): Request
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return $recorded->first()[0];
    }
}
