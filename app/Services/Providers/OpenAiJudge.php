<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\JudgeInterface;
use App\DTOs\JudgementResult;
use App\Services\Judge\JudgeHttp;
use App\Services\Judge\JudgeMedia;
use App\Services\Judge\JudgeRubric;
use RuntimeException;

class OpenAiJudge implements JudgeInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $videoFps,
        private readonly int $maxFrames,
        private readonly JudgeRubric $rubric = new JudgeRubric,
        private readonly JudgeMedia $media = new JudgeMedia,
        private readonly JudgeHttp $http = new JudgeHttp,
    ) {}

    public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        $bytes = $this->media->read($imagePath);

        return $this->request(false, [
            ['type' => 'text', 'text' => $this->rubric->prompt(false, $prompt, $styleAnchor)],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $this->dataUri($this->media->imageMime($bytes, $imagePath), $bytes),
                ],
            ],
        ]);
    }

    public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
    {
        $content = [
            ['type' => 'text', 'text' => $this->rubric->prompt(true, $prompt, $styleAnchor, $this->videoFps)],
        ];

        foreach ($this->media->sampleFrames($videoPath, $this->videoFps, $this->maxFrames) as $frame) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $this->dataUri('image/jpeg', $frame),
                ],
            ];
        }

        return $this->request(true, $content);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function request(bool $video, array $content): JudgementResult
    {
        $payload = $this->http->post('OpenAI', 'https://api.openai.com/v1/chat/completions', [
            'Authorization' => 'Bearer '.$this->apiKey,
        ], [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'shot_judgement',
                    'strict' => true,
                    'schema' => $this->rubric->schema($video),
                ],
            ],
        ]);

        return $this->rubric->parse($this->responseText($payload), $video);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function responseText(array $payload): string
    {
        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenAI judge returned invalid JSON.');
        }

        return $content;
    }

    private function dataUri(string $mime, string $bytes): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
