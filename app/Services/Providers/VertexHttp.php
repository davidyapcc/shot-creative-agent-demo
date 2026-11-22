<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Exceptions\ProviderRateLimitedException;
use Illuminate\Http\Client\Response;
use RuntimeException;

class VertexHttp
{
    /**
     * @return array<string, mixed>
     */
    public static function decodeJson(Response $response, string $label): array
    {
        if ($response->status() === 429) {
            throw new ProviderRateLimitedException("{$label} returned HTTP 429.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$label} returned HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException("{$label} returned invalid JSON.");
        }

        return $json;
    }
}
