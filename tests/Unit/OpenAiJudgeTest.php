<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\JudgementVerdict;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Providers\OpenAiJudge;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OpenAiJudgeTest extends TestCase
{
    private const API_KEY = 'secret-key';

    public function test_image_verdict_uses_the_shared_rubric_and_not_a_model_verdict(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        $this->fakeOpenAi($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 2,
            'object_integrity' => 5,
        ], [], 'Left hand has six fingers.', 'pass'));

        $verdict = $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley', 'cinematic neon rain');

        $this->assertSame(JudgementVerdict::Fail, $verdict->verdict());
        $this->assertSame(2, $verdict->scores['anatomy']);

        $request = $this->request();
        $content = $request->data()['messages'][0]['content'];
        $schema = $request->data()['response_format']['json_schema'];
        $scores = $schema['schema']['properties']['scores']['properties'];

        $this->assertTrue($schema['strict']);
        $this->assertSame('gpt-5.4-mini', $request->data()['model']);
        $this->assertSame(
            ['prompt_adherence', 'anatomy', 'object_integrity'],
            array_keys($scores),
        );
        $this->assertSame(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            $scores['anatomy'],
        );
        $this->assertStringNotContainsString('temporal_consistency', $content[0]['text']);
        $this->assertStringContainsString('Matches the brief and shot plan.', $content[0]['text']);
        $this->assertStringContainsString('no warped wings, melted props', $content[0]['text']);
        $this->assertStringNotContainsString('consecutive frames', $content[0]['text']);
        $this->assertStringContainsString('Style anchor: cinematic neon rain', $content[0]['text']);
        $this->assertCount(1, array_filter($content, fn (array $part): bool => $part['type'] === 'image_url'));
        $this->assertSame(
            'data:image/png;base64,'.base64_encode($this->png()),
            $content[1]['image_url']['url'],
        );
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
        $this->assertStringNotContainsString(self::API_KEY, $request->url());
    }

    public function test_passing_image_scores_produce_a_pass_verdict(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        $this->fakeOpenAi($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 5,
            'object_integrity' => 3,
        ], [], 'The alley matches the plan.'));

        $verdict = $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');

        $this->assertSame(JudgementVerdict::Pass, $verdict->verdict());
        $this->assertSame([
            'prompt_adherence' => 4,
            'anatomy' => 5,
            'object_integrity' => 3,
        ], $verdict->scores);
        $this->assertSame([], $verdict->hardFailTags);
    }

    public function test_malformed_image_responses_are_rejected(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());

        $cases = [
            'missing dimension' => [
                $this->judgement(['anatomy' => 5, 'object_integrity' => 5], [], 'Missing one score.'),
                'prompt_adherence',
            ],
            'score out of range' => [
                $this->judgement([
                    'prompt_adherence' => 6,
                    'anatomy' => 5,
                    'object_integrity' => 5,
                ], [], 'Too high.'),
                '1 to 5',
            ],
            'unknown tag' => [
                $this->judgement([
                    'prompt_adherence' => 5,
                    'anatomy' => 5,
                    'object_integrity' => 5,
                ], ['made_up'], 'Unknown.'),
                'unknown hard-fail',
            ],
            'non-json' => ['not json', 'not valid JSON'],
        ];

        $sequence = Http::sequence();

        foreach ($cases as [$payload]) {
            $sequence->push([
                'choices' => [[
                    'message' => ['content' => $payload],
                ]],
            ]);
        }

        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => $sequence,
        ]);

        foreach ($cases as $name => [, $message]) {
            try {
                $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
                $this->fail($name);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
    }

    public function test_video_is_judged_from_ordered_frames_within_the_frame_cap(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', 'clip-bytes');
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            $this->assertContains('fps=5', $command);
            $this->assertContains('2', $command);
            $directory = dirname((string) $command[array_key_last($command)]);
            file_put_contents($directory.'/frame_0001.jpg', 'frame-a');
            file_put_contents($directory.'/frame_0002.jpg', 'frame-b');
            file_put_contents($directory.'/frame_0003.jpg', 'frame-c');

            return Process::result();
        });
        $this->fakeOpenAi($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 5,
            'object_integrity' => 5,
            'temporal_consistency' => 3,
        ], [], 'Frames hold.'));

        $verdict = $this->judge(maxFrames: 2)->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley', 'cinematic neon rain');

        $this->assertSame(JudgementVerdict::Pass, $verdict->verdict());
        $this->assertSame(3, $verdict->scores['temporal_consistency']);

        $request = $this->request();
        $content = $request->data()['messages'][0]['content'];
        $scores = $request->data()['response_format']['json_schema']['schema']['properties']['scores']['properties'];
        $images = array_values(array_filter($content, fn (array $part): bool => $part['type'] === 'image_url'));

        $this->assertArrayHasKey('temporal_consistency', $scores);
        $this->assertStringContainsString('consecutive frames', $content[0]['text']);
        $this->assertStringContainsString('temporal_consistency', $content[0]['text']);
        $this->assertStringContainsString('No flicker or morphing between frames.', $content[0]['text']);
        $this->assertCount(2, $images);
        $this->assertSame('frame-a', $this->imageBytes($images[0]['image_url']['url']));
        $this->assertSame('frame-b', $this->imageBytes($images[1]['image_url']['url']));
    }

    public function test_video_response_missing_temporal_consistency_is_rejected(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', 'clip-bytes');
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            $directory = dirname((string) $command[array_key_last($command)]);
            file_put_contents($directory.'/frame_0001.jpg', 'frame-a');

            return Process::result();
        });
        $this->fakeOpenAi($this->judgement([
            'prompt_adherence' => 5,
            'anatomy' => 5,
            'object_integrity' => 5,
        ], [], 'No temporal score.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporal_consistency');

        $this->judge()->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley');
    }

    public function test_rate_limit_raises_provider_rate_limited_exception(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => self::API_KEY], 429),
        ]);

        try {
            $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
            $this->fail('A 429 should be retryable.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('OpenAI judge returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function test_other_http_errors_fail_without_exposing_the_api_key(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        Http::fake([
            'api.openai.com/*' => Http::response(self::API_KEY, 500),
        ]);

        try {
            $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
            $this->fail('A provider error should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI judge returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    private function judge(int $maxFrames = 48): OpenAiJudge
    {
        return new OpenAiJudge(self::API_KEY, 'gpt-5.4-mini', 5, $maxFrames);
    }

    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n".'pixels';
    }

    /**
     * @param  array<string, int>  $scores
     * @param  list<string>  $tags
     */
    private function judgement(array $scores, array $tags, string $reason, ?string $verdict = null): string
    {
        $body = [
            'scores' => $scores,
            'hard_fail_tags' => $tags,
            'reason' => $reason,
        ];

        if ($verdict !== null) {
            $body['verdict'] = $verdict;
        }

        return (string) json_encode($body);
    }

    private function fakeOpenAi(string $text): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => $text],
                ]],
            ]),
        ]);
    }

    private function request(): Request
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return $recorded->first()[0];
    }

    private function imageBytes(string $dataUri): string
    {
        return (string) base64_decode((string) explode(',', $dataUri, 2)[1], true);
    }
}
