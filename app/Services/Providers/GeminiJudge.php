<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Services\Judge\JudgeHttp;
use App\Services\Judge\JudgeMedia;
use App\Services\Judge\JudgeRubric;
use RuntimeException;

class GeminiJudge implements JudgeInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $videoFps,
        private readonly JudgeRubric $rubric = new JudgeRubric,
        private readonly JudgeMedia $media = new JudgeMedia,
        private readonly JudgeHttp $http = new JudgeHttp,
    ) {}

    public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        $bytes = $this->media->read($imagePath);

        return $this->request(false, [
            [
                'inlineData' => [
                    'mimeType' => $this->media->imageMime($bytes, $imagePath),
                    'data' => base64_encode($bytes),
                ],
            ],
            ['text' => $this->rubric->prompt(false, $prompt, $styleAnchor)],
        ]);
    }

    public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        $bytes = $this->media->inlineVideo($videoPath);

        return $this->request(true, [
            [
                'inlineData' => [
                    'mimeType' => 'video/mp4',
                    'data' => base64_encode($bytes),
                ],
                'videoMetadata' => [
                    'fps' => $this->videoFps,
                ],
            ],
            ['text' => $this->rubric->prompt(true, $prompt, $styleAnchor)],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function request(bool $video, array $parts): JudgementResult
    {
        $payload = $this->http->post('Gemini', $this->endpoint(), [
            'x-goog-api-key' => $this->apiKey,
        ], [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->rubric->schema($video),
            ],
        ]);

        return $this->rubric->parse($this->responseText($payload), $video);
    }

    private function endpoint(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($this->model)
            .':generateContent';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function responseText(array $payload): string
    {
        $parts = $payload['candidates'][0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            throw new RuntimeException('Gemini judge returned invalid JSON.');
        }

        $text = '';

        foreach ($parts as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            throw new RuntimeException('Gemini judge returned invalid JSON.');
        }

        return $text;
    }
}
