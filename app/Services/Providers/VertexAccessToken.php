<?php

declare(strict_types=1);

namespace App\Services\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exchanges a Google service account JSON credential for a cached Vertex AI OAuth2 access token.
 */
class VertexAccessToken
{
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const TIMEOUT_SECONDS = 30;

    private const JWT_TTL_SECONDS = 3600;

    private const CACHE_SAFETY_MARGIN_SECONDS = 60;

    private function __construct(
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $tokenUri,
    ) {}

    public static function fromCredentials(string $credentials): self
    {
        $trimmed = trim($credentials);

        if ($trimmed === '') {
            throw new InvalidArgumentException('GOOGLE_APPLICATION_CREDENTIALS is required when VIDEO_PROVIDER=veo.');
        }

        $rawJson = self::readRawJson($trimmed);
        $decoded = json_decode($rawJson, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
            );
        }

        $clientEmail = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;

        if (! is_string($clientEmail) || trim($clientEmail) === '' || ! is_string($privateKey) || trim($privateKey) === '') {
            throw new InvalidArgumentException(
                'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
            );
        }

        if (openssl_pkey_get_private($privateKey) === false) {
            throw new InvalidArgumentException('GOOGLE_APPLICATION_CREDENTIALS contains an invalid private_key.');
        }

        $tokenUri = $decoded['token_uri'] ?? self::DEFAULT_TOKEN_URI;

        if (! is_string($tokenUri) || trim($tokenUri) === '') {
            $tokenUri = self::DEFAULT_TOKEN_URI;
        }

        return new self(
            clientEmail: trim($clientEmail),
            privateKey: $privateKey,
            tokenUri: trim($tokenUri),
        );
    }

    public function token(): string
    {
        $cacheKey = 'vertex_access_token_'.hash('sha256', $this->clientEmail.'|'.$this->tokenUri);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt(),
            ]);

        $payload = VertexHttp::decodeJson($response, 'Google OAuth token endpoint');
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google OAuth token response is missing access_token.');
        }

        $expiresIn = is_numeric($payload['expires_in'] ?? null)
            ? (int) $payload['expires_in']
            : self::JWT_TTL_SECONDS;

        $ttl = $expiresIn > self::CACHE_SAFETY_MARGIN_SECONDS
            ? $expiresIn - self::CACHE_SAFETY_MARGIN_SECONDS
            : ($expiresIn > 5 ? $expiresIn - 5 : 0);

        if ($ttl > 0) {
            Cache::put($cacheKey, $accessToken, $ttl);
        }

        return $accessToken;
    }

    private function signedJwt(): string
    {
        $now = time();
        $header = $this->base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $claims = $this->base64UrlEncode((string) json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + self::JWT_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        $signingInput = "{$header}.{$claims}";
        $privateKey = openssl_pkey_get_private($this->privateKey);

        if ($privateKey === false) {
            throw new RuntimeException('Unable to load Vertex service account private key.');
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Vertex OAuth JWT assertion.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function readRawJson(string $credentials): string
    {
        if (is_file($credentials)) {
            $contents = is_readable($credentials) ? file_get_contents($credentials) : false;

            if (! is_string($contents)) {
                throw new InvalidArgumentException("Google credentials file not found at [{$credentials}].");
            }

            return $contents;
        }

        if (str_starts_with($credentials, '{')) {
            return $credentials;
        }

        if (str_contains($credentials, '/') || str_contains($credentials, '\\') || str_ends_with(strtolower($credentials), '.json')) {
            throw new InvalidArgumentException("Google credentials file not found at [{$credentials}].");
        }

        throw new InvalidArgumentException(
            'GOOGLE_APPLICATION_CREDENTIALS must contain valid service account JSON with client_email and private_key.',
        );
    }
}
