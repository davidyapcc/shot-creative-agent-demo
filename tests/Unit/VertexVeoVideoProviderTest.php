<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\VideoProviderInterface;
use App\DTOs\VideoRenderResult;
use App\Enums\VideoRenderStatus;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Providers\ReplayVideoProvider;
use App\Services\Providers\VertexVeoVideoProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class VertexVeoVideoProviderTest extends TestCase
{
    private const PROJECT_ID = 'shot-creative-trial';

    private const LOCATION = 'us-central1';

    private const MODEL = 'veo-3.1-fast-generate-001';

    private const CLIENT_EMAIL = 'veo-runner@shot-creative-trial.iam.gserviceaccount.com';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private static ?string $cachedPrivateKey = null;

    public function test_replay_stays_the_default_without_google_credentials(): void
    {
        config([
            'creative.video' => 'replay',
            'services.vertex.project_id' => null,
            'services.vertex.credentials' => null,
        ]);

        $this->assertInstanceOf(ReplayVideoProvider::class, app(VideoProviderInterface::class));
    }

    public function test_vertex_veo_video_provider_resolves_from_container_with_inline_json(): void
    {
        config([
            'creative.video' => 'veo',
            'services.vertex.project_id' => self::PROJECT_ID,
            'services.vertex.location' => self::LOCATION,
            'services.vertex.veo_model' => self::MODEL,
            'services.vertex.credentials' => $this->credentialsJson(),
        ]);

        $this->assertInstanceOf(VertexVeoVideoProvider::class, app(VideoProviderInterface::class));
    }

    public function test_vertex_veo_video_provider_resolves_from_container_with_credentials_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vertex_creds_');
        $this->assertIsString($path);
        file_put_contents($path, $this->credentialsJson());

        try {
            config([
                'creative.video' => 'veo',
                'services.vertex.project_id' => self::PROJECT_ID,
                'services.vertex.location' => self::LOCATION,
                'services.vertex.veo_model' => self::MODEL,
                'services.vertex.credentials' => $path,
            ]);

            $this->assertInstanceOf(VertexVeoVideoProvider::class, app(VideoProviderInterface::class));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_vertex_veo_video_provider_requires_project_location_model_and_credentials(): void
    {
        $cases = [
            'missing project' => [
                'services.vertex.project_id' => '  ',
                'services.vertex.location' => self::LOCATION,
                'services.vertex.veo_model' => self::MODEL,
                'services.vertex.credentials' => $this->credentialsJson(),
                'expected' => 'VERTEX_PROJECT_ID is required when VIDEO_PROVIDER=veo.',
            ],
            'missing location' => [
                'services.vertex.project_id' => self::PROJECT_ID,
                'services.vertex.location' => '',
                'services.vertex.veo_model' => self::MODEL,
                'services.vertex.credentials' => $this->credentialsJson(),
                'expected' => 'VERTEX_LOCATION is required when VIDEO_PROVIDER=veo.',
            ],
            'missing model' => [
                'services.vertex.project_id' => self::PROJECT_ID,
                'services.vertex.location' => self::LOCATION,
                'services.vertex.veo_model' => '',
                'services.vertex.credentials' => $this->credentialsJson(),
                'expected' => 'VERTEX_VEO_MODEL is required when VIDEO_PROVIDER=veo.',
            ],
            'missing credentials' => [
                'services.vertex.project_id' => self::PROJECT_ID,
                'services.vertex.location' => self::LOCATION,
                'services.vertex.veo_model' => self::MODEL,
                'services.vertex.credentials' => null,
                'expected' => 'GOOGLE_APPLICATION_CREDENTIALS is required when VIDEO_PROVIDER=veo.',
            ],
        ];

        foreach ($cases as $label => $case) {
            config([
                'creative.video' => 'veo',
                'services.vertex.project_id' => $case['services.vertex.project_id'],
                'services.vertex.location' => $case['services.vertex.location'],
                'services.vertex.veo_model' => $case['services.vertex.veo_model'],
                'services.vertex.credentials' => $case['services.vertex.credentials'],
            ]);

            try {
                app(VideoProviderInterface::class);
                $this->fail("Expected InvalidArgumentException for {$label}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($case['expected'], $exception->getMessage(), $label);
            }
        }
    }

    public function test_vertex_veo_video_provider_rejects_missing_file_malformed_json_and_invalid_key(): void
    {
        $missingFile = sys_get_temp_dir().'/missing-vertex-creds-'.uniqid('', true).'.json';
        $badKeyJson = json_encode([
            'client_email' => self::CLIENT_EMAIL,
            'private_key' => 'SECRET_NOT_A_REAL_KEY',
        ], JSON_THROW_ON_ERROR);

        $cases = [
            'missing file' => [
                'credentials' => $missingFile,
                'expected' => "Google credentials file not found at [{$missingFile}].",
                'secret' => null,
            ],
            'malformed json' => [
                'credentials' => '{not-valid-json',
                'expected' => 'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
                'secret' => null,
            ],
            'non-json string' => [
                'credentials' => 'not-json-at-all',
                'expected' => 'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
                'secret' => null,
            ],
            'missing client_email' => [
                'credentials' => json_encode(['private_key' => $this->privateKey()], JSON_THROW_ON_ERROR),
                'expected' => 'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
                'secret' => $this->privateKey(),
            ],
            'invalid private key' => [
                'credentials' => $badKeyJson,
                'expected' => 'GOOGLE_APPLICATION_CREDENTIALS contains an invalid private_key.',
                'secret' => 'SECRET_NOT_A_REAL_KEY',
            ],
        ];

        foreach ($cases as $label => $case) {
            config([
                'creative.video' => 'veo',
                'services.vertex.project_id' => self::PROJECT_ID,
                'services.vertex.location' => self::LOCATION,
                'services.vertex.veo_model' => self::MODEL,
                'services.vertex.credentials' => $case['credentials'],
            ]);

            try {
                app(VideoProviderInterface::class);
                $this->fail("Expected InvalidArgumentException for {$label}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($case['expected'], $exception->getMessage(), $label);

                if (is_string($case['secret'])) {
                    $this->assertStringNotContainsString($case['secret'], $exception->getMessage(), $label);
                }
            }
        }
    }

    public function test_unknown_video_provider_is_rejected(): void
    {
        config(['creative.video' => 'sora']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported video provider [sora].');

        app(VideoProviderInterface::class);
    }

    public function test_submit_throws_when_keyframe_is_missing_from_s3_without_making_http_requests(): void
    {
        Storage::fake('s3');
        Http::preventStrayRequests();
        Http::fake();

        $provider = $this->provider();

        try {
            $provider->submit('A rainy neon street', 'keyframes/missing.png');
            $this->fail('Expected InvalidArgumentException when keyframe is missing.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Keyframe not found at S3 path [keyframes/missing.png].', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_submit_exchanges_signed_jwt_sends_prompt_and_keyframe_and_returns_operation_name(): void
    {
        Cache::flush();
        Storage::fake('s3');
        $pngBytes = "\x89PNG\r\n\x1a\n".'fake-keyframe-png';
        Storage::disk('s3')->put('keyframes/shot_1.png', $pngBytes);

        $operationName = 'projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.'/operations/op-123';

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-token',
                'expires_in' => 3600,
            ]),
            'us-central1-aiplatform.googleapis.com/*' => Http::response([
                'name' => $operationName,
            ]),
        ]);

        $renderId = $this->provider()->submit('A rainy neon street', 'keyframes/shot_1.png');

        $this->assertSame($operationName, $renderId);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        /** @var Request $tokenRequest */
        $tokenRequest = $recorded[0][0];
        $this->assertSame('POST', $tokenRequest->method());
        $this->assertSame(self::TOKEN_URI, $tokenRequest->url());
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $tokenRequest->data()['grant_type']);
        $this->assertValidJwtAssertion((string) $tokenRequest->data()['assertion']);

        /** @var Request $predictRequest */
        $predictRequest = $recorded[1][0];
        $this->assertSame('POST', $predictRequest->method());
        $this->assertSame(
            'https://us-central1-aiplatform.googleapis.com/v1/projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.':predictLongRunning',
            $predictRequest->url(),
        );
        $this->assertTrue($predictRequest->hasHeader('Authorization', 'Bearer ya29.test-token'));

        $payload = $predictRequest->data();
        $this->assertSame([
            'instances' => [
                [
                    'prompt' => 'A rainy neon street',
                    'image' => [
                        'bytesBase64Encoded' => base64_encode($pngBytes),
                        'mimeType' => 'image/png',
                    ],
                ],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'generateAudio' => false,
            ],
        ], $payload);
        $this->assertArrayNotHasKey('storageUri', $payload['parameters']);
    }

    public function test_submit_detects_jpeg_keyframe_and_supports_global_location_endpoint(): void
    {
        Cache::flush();
        Storage::fake('s3');
        $jpegBytes = "\xFF\xD8\xFF".'fake-keyframe-jpeg';
        Storage::disk('s3')->put('keyframes/shot_2.dat', $jpegBytes);

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.global-token',
                'expires_in' => 3600,
            ]),
            'aiplatform.googleapis.com/*' => Http::response([
                'name' => 'projects/'.self::PROJECT_ID.'/locations/global/publishers/google/models/'.self::MODEL.'/operations/op-global',
            ]),
        ]);

        $provider = new VertexVeoVideoProvider(
            self::PROJECT_ID,
            'global',
            self::MODEL,
            $this->credentialsJson(),
        );

        $renderId = $provider->submit('A car speeds past', 'keyframes/shot_2.dat');

        $this->assertSame('projects/'.self::PROJECT_ID.'/locations/global/publishers/google/models/'.self::MODEL.'/operations/op-global', $renderId);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame(
            'https://aiplatform.googleapis.com/v1/projects/'.self::PROJECT_ID.'/locations/global/publishers/google/models/'.self::MODEL.':predictLongRunning',
            $recorded[1][0]->url(),
        );
        $this->assertSame('image/jpeg', $recorded[1][0]->data()['instances'][0]['image']['mimeType']);
    }

    public function test_submit_handles_rate_limits_http_errors_and_missing_operation_name_without_leaking_secrets(): void
    {
        Cache::flush();
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', "\x89PNG\r\n\x1a\n".'png');

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['error' => 'slow down'], 429)
                ->push($this->privateKey(), 500)
                ->push(['access_token' => 'ya29.secret-token', 'expires_in' => 3600]),
            'us-central1-aiplatform.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'quota'], 429)
                ->push('ya29.secret-token '.$this->privateKey(), 503)
                ->push(['done' => false], 200),
        ]);

        try {
            $this->provider()->submit('Shot 1', 'keyframes/shot_1.png');
            $this->fail('Expected ProviderRateLimitedException on OAuth 429.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('Google OAuth token endpoint returned HTTP 429.', $exception->getMessage());
        }

        try {
            $this->provider()->submit('Shot 1', 'keyframes/shot_1.png');
            $this->fail('Expected RuntimeException on OAuth 500.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Google OAuth token endpoint returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString($this->privateKey(), $exception->getMessage());
        }

        try {
            $this->provider()->submit('Shot 1', 'keyframes/shot_1.png');
            $this->fail('Expected ProviderRateLimitedException on Veo 429.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('Vertex Veo returned HTTP 429.', $exception->getMessage());
        }

        try {
            $this->provider()->submit('Shot 1', 'keyframes/shot_1.png');
            $this->fail('Expected RuntimeException on Veo 503.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Vertex Veo returned HTTP 503.', $exception->getMessage());
            $this->assertStringNotContainsString('ya29.secret-token', $exception->getMessage());
            $this->assertStringNotContainsString($this->privateKey(), $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vertex Veo submit response is missing an operation name.');
        $this->provider()->submit('Shot 1', 'keyframes/shot_1.png');
    }

    public function test_submit_and_poll_reuses_cached_oauth_token_and_maps_pending_then_completed_video_bytes(): void
    {
        Cache::flush();
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', "\x89PNG\r\n\x1a\n".'png');

        $operationName = 'projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.'/operations/op-456';
        $mp4Bytes = "\x00\x00\x00\x20ftypisom".random_bytes(32);

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'ya29.cached-token', 'expires_in' => 3600]),
            'us-central1-aiplatform.googleapis.com/*' => Http::sequence()
                ->push(['name' => $operationName])
                ->push(['name' => $operationName, 'done' => false])
                ->push(['name' => $operationName])
                ->push([
                    'name' => $operationName,
                    'done' => true,
                    'response' => [
                        '@type' => 'type.googleapis.com/cloud.ai.large_models.vision.GenerateVideoResponse',
                        'raiMediaFilteredCount' => 0,
                        'videos' => [
                            [
                                'bytesBase64Encoded' => base64_encode($mp4Bytes),
                                'mimeType' => 'video/mp4',
                            ],
                        ],
                    ],
                ]),
        ]);

        $provider = $this->provider();
        $renderId = $provider->submit('A neon alley', 'keyframes/shot_1.png');

        $firstPoll = $provider->poll($renderId);
        $this->assertInstanceOf(VideoRenderResult::class, $firstPoll);
        $this->assertTrue($firstPoll->isPending());
        $this->assertSame(VideoRenderStatus::Pending, $firstPoll->status);
        $this->assertNull($firstPoll->videoContent);

        $secondPoll = $provider->poll($renderId);
        $this->assertTrue($secondPoll->isPending());

        $thirdPoll = $provider->poll($renderId);
        $this->assertTrue($thirdPoll->isCompleted());
        $this->assertSame(VideoRenderStatus::Completed, $thirdPoll->status);
        $this->assertSame($mp4Bytes, $thirdPoll->videoContent);

        $recorded = Http::recorded();
        // 1 token request + 1 submit + 3 single-fetch polls = 5 HTTP requests total.
        $this->assertCount(5, $recorded);

        foreach ([2, 3, 4] as $index) {
            /** @var Request $pollRequest */
            $pollRequest = $recorded[$index][0];
            $this->assertSame('POST', $pollRequest->method());
            $this->assertSame(
                'https://us-central1-aiplatform.googleapis.com/v1/projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.':fetchPredictOperation',
                $pollRequest->url(),
            );
            $this->assertTrue($pollRequest->hasHeader('Authorization', 'Bearer ya29.cached-token'));
            $this->assertSame(['operationName' => $operationName], $pollRequest->data());
        }
    }

    public function test_poll_maps_operation_error_safety_filter_and_empty_result_with_distinct_reasons(): void
    {
        Cache::flush();

        $operationName = 'projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.'/operations/op-789';

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.poll-token',
                'expires_in' => 3600,
            ]),
            'us-central1-aiplatform.googleapis.com/*' => Http::sequence()
                ->push([
                    'name' => $operationName,
                    'done' => true,
                    'error' => [
                        'code' => 13,
                        'message' => 'Internal backend failure during rendering.',
                    ],
                ])
                ->push([
                    'name' => $operationName,
                    'done' => true,
                    'response' => [
                        'raiMediaFilteredCount' => 1,
                        'raiMediaFilteredReasons' => ['Generated video violated Responsible AI policies.'],
                        'videos' => [],
                    ],
                ])
                ->push([
                    'name' => $operationName,
                    'done' => true,
                    'response' => [
                        'raiMediaFilteredCount' => 0,
                        'videos' => [],
                    ],
                ])
                ->push([
                    'name' => $operationName,
                    'done' => true,
                    'response' => [
                        'videos' => [
                            ['bytesBase64Encoded' => 'not-valid-base64!!!'],
                        ],
                    ],
                ]),
        ]);

        $provider = $this->provider();

        $operationError = $provider->poll($operationName);
        $this->assertTrue($operationError->isFailed());
        $this->assertStringContainsString('operation error', strtolower((string) $operationError->error));
        $this->assertStringContainsString('Internal backend failure during rendering.', (string) $operationError->error);

        $safetyFiltered = $provider->poll($operationName);
        $this->assertTrue($safetyFiltered->isFailed());
        $this->assertStringContainsString('safety-filtered', strtolower((string) $safetyFiltered->error));
        $this->assertStringContainsString('Responsible AI policies', (string) $safetyFiltered->error);

        $emptyResult = $provider->poll($operationName);
        $this->assertTrue($emptyResult->isFailed());
        $this->assertStringContainsString('empty video result', strtolower((string) $emptyResult->error));

        $invalidPayload = $provider->poll($operationName);
        $this->assertTrue($invalidPayload->isFailed());
        $this->assertStringContainsString('invalid video payload', strtolower((string) $invalidPayload->error));

        $reasons = [
            $operationError->error,
            $safetyFiltered->error,
            $emptyResult->error,
            $invalidPayload->error,
        ];
        $this->assertCount(4, array_unique($reasons));
    }

    public function test_poll_raises_rate_limit_and_http_errors_without_leaking_secrets(): void
    {
        Cache::flush();

        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.poll-secret-token',
                'expires_in' => 3600,
            ]),
            'us-central1-aiplatform.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push('ya29.poll-secret-token '.$this->privateKey(), 502),
        ]);

        $operationName = 'projects/'.self::PROJECT_ID.'/locations/'.self::LOCATION.'/publishers/google/models/'.self::MODEL.'/operations/op-err';

        try {
            $this->provider()->poll($operationName);
            $this->fail('Expected ProviderRateLimitedException on poll 429.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('Vertex Veo returned HTTP 429.', $exception->getMessage());
        }

        try {
            $this->provider()->poll($operationName);
            $this->fail('Expected RuntimeException on poll 502.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Vertex Veo returned HTTP 502.', $exception->getMessage());
            $this->assertStringNotContainsString('ya29.poll-secret-token', $exception->getMessage());
            $this->assertStringNotContainsString($this->privateKey(), $exception->getMessage());
        }
    }

    private function provider(): VertexVeoVideoProvider
    {
        return new VertexVeoVideoProvider(
            self::PROJECT_ID,
            self::LOCATION,
            self::MODEL,
            $this->credentialsJson(),
        );
    }

    private function assertValidJwtAssertion(string $jwt): void
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $claims = json_decode($this->base64UrlDecode($encodedClaims), true);
        $signature = $this->base64UrlDecode($encodedSignature);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame(self::CLIENT_EMAIL, $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/cloud-platform', $claims['scope']);
        $this->assertSame(self::TOKEN_URI, $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);

        $key = openssl_pkey_get_private($this->privateKey());
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        $verified = openssl_verify("{$encodedHeader}.{$encodedClaims}", $signature, $details['key'], OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified);
    }

    private function base64UrlDecode(string $encoded): string
    {
        $remainder = strlen($encoded) % 4;

        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    private function credentialsJson(): string
    {
        return json_encode([
            'client_email' => self::CLIENT_EMAIL,
            'private_key' => $this->privateKey(),
            'token_uri' => self::TOKEN_URI,
        ], JSON_THROW_ON_ERROR);
    }

    private function privateKey(): string
    {
        if (self::$cachedPrivateKey !== null) {
            return self::$cachedPrivateKey;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key, 'OpenSSL must generate an RSA key for tests.');

        $pem = '';
        $exported = openssl_pkey_export($key, $pem);
        $this->assertTrue($exported, 'OpenSSL must export an RSA private key.');

        return self::$cachedPrivateKey = $pem;
    }
}
