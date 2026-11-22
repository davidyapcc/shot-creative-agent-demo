<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\VideoProviderInterface;
use App\DTOs\VideoRenderResult;
use App\Support\ImageMime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Vertex AI Veo image-to-video driver using long-running predictions and inline video responses.
 */
class VertexVeoVideoProvider implements VideoProviderInterface
{
    private const TIMEOUT_SECONDS = 45;

    private readonly string $projectId;

    private readonly string $location;

    private readonly string $model;

    private readonly VertexAccessToken $accessToken;

    public function __construct(
        string $projectId,
        string $location,
        string $model,
        string $credentials,
    ) {
        $this->projectId = self::requireNonEmpty($projectId, 'VERTEX_PROJECT_ID is required when VIDEO_PROVIDER=veo.');
        $this->location = self::requireNonEmpty($location, 'VERTEX_LOCATION is required when VIDEO_PROVIDER=veo.');
        $this->model = self::requireNonEmpty($model, 'VERTEX_VEO_MODEL is required when VIDEO_PROVIDER=veo.');
        $this->accessToken = VertexAccessToken::fromCredentials($credentials);
    }

    public function submit(string $prompt, string $keyframePath): string
    {
        $imageBytes = $this->readKeyframe($keyframePath);

        $json = $this->post($this->modelEndpoint().':predictLongRunning', [
            'instances' => [
                [
                    'prompt' => $prompt,
                    'image' => [
                        'bytesBase64Encoded' => base64_encode($imageBytes),
                        'mimeType' => ImageMime::detect($imageBytes, $keyframePath),
                    ],
                ],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'generateAudio' => false,
            ],
        ]);

        $name = $json['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Vertex Veo submit response is missing an operation name.');
        }

        return trim($name);
    }

    public function poll(string $renderId): VideoRenderResult
    {
        $json = $this->post($this->modelEndpoint().':fetchPredictOperation', [
            'operationName' => $renderId,
        ]);

        if (($json['done'] ?? false) !== true) {
            return VideoRenderResult::pending();
        }

        if (isset($json['error']) && is_array($json['error'])) {
            return VideoRenderResult::failed($this->operationErrorMessage($json['error']));
        }

        $response = is_array($json['response'] ?? null) ? $json['response'] : [];
        $videos = is_array($response['videos'] ?? null) ? $response['videos'] : [];
        $encoded = is_array($videos[0] ?? null) ? ($videos[0]['bytesBase64Encoded'] ?? null) : null;

        if (is_string($encoded) && $encoded !== '') {
            $decoded = base64_decode($encoded, true);

            if (is_string($decoded) && $decoded !== '') {
                return VideoRenderResult::completed($decoded);
            }

            return VideoRenderResult::failed('Vertex Veo returned an empty or invalid video payload.');
        }

        $filteredMessage = $this->safetyFilterMessage($response);

        if ($filteredMessage !== null) {
            return VideoRenderResult::failed($filteredMessage);
        }

        return VideoRenderResult::failed('Vertex Veo returned an empty video result.');
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function operationErrorMessage(array $error): string
    {
        $code = $error['code'] ?? null;
        $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        $codeLabel = (is_int($code) || is_string($code)) && (string) $code !== '' ? " (code {$code})" : '';

        if ($message !== '') {
            return "Vertex Veo operation error{$codeLabel}: {$message}";
        }

        return "Vertex Veo operation error{$codeLabel}.";
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function safetyFilterMessage(array $response): ?string
    {
        $filteredCount = (int) ($response['raiMediaFilteredCount'] ?? 0);
        $rawReasons = is_array($response['raiMediaFilteredReasons'] ?? null)
            ? $response['raiMediaFilteredReasons']
            : [];

        $reasons = [];

        foreach ($rawReasons as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $reasons[] = trim($reason);
            }
        }

        if ($filteredCount <= 0 && $reasons === []) {
            return null;
        }

        if ($reasons !== []) {
            return 'Vertex Veo output was safety-filtered: '.implode('; ', $reasons);
        }

        return "Vertex Veo output was safety-filtered ({$filteredCount} filtered).";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload): array
    {
        $response = Http::withToken($this->accessToken->token())
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($url, $payload);

        return VertexHttp::decodeJson($response, 'Vertex Veo');
    }

    private function modelEndpoint(): string
    {
        $host = $this->location === 'global'
            ? 'aiplatform.googleapis.com'
            : "{$this->location}-aiplatform.googleapis.com";

        return 'https://'.$host
            .'/v1/projects/'.rawurlencode($this->projectId)
            .'/locations/'.rawurlencode($this->location)
            .'/publishers/google/models/'.rawurlencode($this->model);
    }

    private function readKeyframe(string $keyframePath): string
    {
        if (! Storage::disk('s3')->exists($keyframePath)) {
            throw new InvalidArgumentException("Keyframe not found at S3 path [{$keyframePath}].");
        }

        $bytes = Storage::disk('s3')->get($keyframePath);

        if (! is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException("Keyframe not found at S3 path [{$keyframePath}].");
        }

        return $bytes;
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
