<?php

declare(strict_types=1);

namespace App\Services\Judge;

use App\Exceptions\ProviderRateLimitedException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JudgeHttp
{
    private const TIMEOUT_SECONDS = 50;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $provider, string $url, array $headers, array $payload): array
    {
        $response = Http::withHeaders($headers)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($url, $payload);

        if ($response->status() === 429) {
            throw new ProviderRateLimitedException("{$provider} judge returned HTTP 429.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$provider} judge returned HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException("{$provider} judge returned invalid JSON.");
        }

        return $json;
    }
}
