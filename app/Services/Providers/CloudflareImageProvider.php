<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ImageProviderInterface;
use App\Exceptions\ProviderRateLimitedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class CloudflareImageProvider implements ImageProviderInterface
{
    private const TIMEOUT_SECONDS = 45;

    private const DEFAULT_MODEL = '@cf/black-forest-labs/flux-1-schnell';

    private readonly string $accountId;

    private readonly string $apiToken;

    private readonly string $model;

    public function __construct(
        string $accountId,
        string $apiToken,
        string $model = self::DEFAULT_MODEL,
    ) {
        $this->accountId = self::requireNonEmpty($accountId, 'CLOUDFLARE_ACCOUNT_ID is required when IMAGE_PROVIDER=cloudflare.');
        $this->apiToken = self::requireNonEmpty($apiToken, 'CLOUDFLARE_API_TOKEN is required when IMAGE_PROVIDER=cloudflare.');
        $this->model = self::requireNonEmpty($model, 'IMAGE_MODEL is required when IMAGE_PROVIDER=cloudflare.');
    }

    public function generateImage(string $prompt, ?string $styleAnchor = null): string
    {
        $response = Http::withToken($this->apiToken)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->endpoint(), [
                'prompt' => $this->composePrompt($prompt, $styleAnchor),
            ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimitedException('Cloudflare image provider returned HTTP 429.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Cloudflare image provider returned HTTP {$response->status()}.");
        }

        return $this->extractImageBytes($response);
    }

    private function endpoint(): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($this->accountId).'/ai/run/'.ltrim($this->model, '/');
    }

    private function composePrompt(string $prompt, ?string $styleAnchor): string
    {
        if (! is_string($styleAnchor) || trim($styleAnchor) === '') {
            return $prompt;
        }

        return rtrim(trim($styleAnchor), '.').'. '.$prompt;
    }

    private function extractImageBytes(Response $response): string
    {
        $body = $response->body();

        if ($this->isValidImage($body)) {
            return $body;
        }

        $json = $response->json();
        $encoded = is_array($json) ? ($json['result']['image'] ?? null) : null;

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Cloudflare image provider returned invalid or corrupt image data.');
        }

        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded) || ! $this->isValidImage($decoded)) {
            throw new RuntimeException('Cloudflare image provider returned invalid or corrupt image data.');
        }

        return $decoded;
    }

    private function isValidImage(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || str_starts_with($bytes, "\xFF\xD8\xFF");
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
