<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\JudgementVerdict;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Judge\JudgeMedia;
use App\Services\Providers\GeminiJudge;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GeminiJudgeTest extends TestCase
{
    private const API_KEY = 'secret-key';

    public function test_image_verdict_uses_the_shared_rubric_and_not_a_model_verdict(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        $this->fakeGemini($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 2,
            'object_integrity' => 5,
        ], [], 'Left hand has six fingers.', 'pass'));

        $verdict = $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley', 'cinematic neon rain');

        $this->assertSame(JudgementVerdict::Fail, $verdict->verdict());
        $this->assertSame(2, $verdict->scores['anatomy']);
        $this->assertSame('Left hand has six fingers.', $verdict->reason);

        $request = $this->request();
        $text = $request->data()['contents'][0]['parts'][1]['text'];
        $scores = $request->data()['generationConfig']['responseJsonSchema']['properties']['scores']['properties'];

        $this->assertSame(
            ['prompt_adherence', 'anatomy', 'object_integrity'],
            array_keys($scores),
        );
        $this->assertSame(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            $scores['prompt_adherence'],
        );
        $this->assertStringNotContainsString('temporal_consistency', $text);
        $this->assertStringContainsString('Matches the brief and shot plan.', $text);
        $this->assertStringContainsString('no warped wings, melted props', $text);
        $this->assertStringContainsString('Shot 1: neon alley', $text);
        $this->assertStringContainsString('Style anchor: cinematic neon rain', $text);
        $this->assertSame('image/png', $request->data()['contents'][0]['parts'][0]['inlineData']['mimeType']);
        $this->assertSame(base64_encode($this->png()), $request->data()['contents'][0]['parts'][0]['inlineData']['data']);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $request->url());
        $this->assertStringNotContainsString(self::API_KEY, $request->url());
        $this->assertTrue($request->hasHeader('x-goog-api-key', self::API_KEY));
    }

    public function test_passing_image_scores_produce_a_pass_verdict(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        $this->fakeGemini($this->judgement([
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
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => $payload],
                        ],
                    ],
                ]],
            ]);
        }

        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => $sequence,
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

    public function test_video_is_sent_inline_with_the_configured_fps_and_temporal_consistency(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', 'small-clip');
        Process::preventStrayProcesses();
        $this->fakeGemini($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 5,
            'object_integrity' => 5,
            'temporal_consistency' => 4,
        ], [], 'Motion holds.'));

        $verdict = $this->judge()->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley', 'cinematic neon rain');

        $this->assertSame(JudgementVerdict::Pass, $verdict->verdict());
        $this->assertSame(4, $verdict->scores['temporal_consistency']);

        $request = $this->request();
        $part = $request->data()['contents'][0]['parts'][0];
        $text = $request->data()['contents'][0]['parts'][1]['text'];
        $scores = $request->data()['generationConfig']['responseJsonSchema']['properties']['scores']['properties'];

        $this->assertSame(base64_encode('small-clip'), $part['inlineData']['data']);
        $this->assertSame('video/mp4', $part['inlineData']['mimeType']);
        $this->assertSame(5, $part['videoMetadata']['fps']);
        $this->assertArrayHasKey('temporal_consistency', $scores);
        $this->assertStringContainsString('temporal_consistency', $text);
        $this->assertStringContainsString('No flicker or morphing between frames.', $text);
        $this->assertStringNotContainsString('consecutive frames', $text);
    }

    public function test_video_response_missing_temporal_consistency_is_rejected(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', 'small-clip');
        Process::preventStrayProcesses();
        $this->fakeGemini($this->judgement([
            'prompt_adherence' => 5,
            'anatomy' => 5,
            'object_integrity' => 5,
        ], [], 'No temporal score.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporal_consistency');

        $this->judge()->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley');
    }

    public function test_oversized_video_is_compressed_before_it_is_sent_inline(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', str_repeat('x', JudgeMedia::MAX_INLINE_BYTES + 1));
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            file_put_contents($command[array_key_last($command)], 'compressed-clip');

            return Process::result();
        });
        $this->fakeGemini($this->judgement([
            'prompt_adherence' => 4,
            'anatomy' => 5,
            'object_integrity' => 5,
            'temporal_consistency' => 4,
        ], [], 'Compressed clip holds.'));

        $this->judge()->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley');

        $part = $this->request()->data()['contents'][0]['parts'][0];
        $this->assertSame(base64_encode('compressed-clip'), $part['inlineData']['data']);
        $this->assertSame(5, $part['videoMetadata']['fps']);
    }

    public function test_video_still_over_ten_megabytes_after_compression_is_rejected(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('videos/shot_1.mp4', str_repeat('x', JudgeMedia::MAX_INLINE_BYTES + 1));
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            file_put_contents($command[array_key_last($command)], str_repeat('y', JudgeMedia::MAX_INLINE_BYTES + 1));

            return Process::result();
        });
        Http::fake();
        Http::preventStrayRequests();

        try {
            $this->judge()->judgeVideo('videos/shot_1.mp4', 'Shot 1: neon alley');
            $this->fail('An oversized clip should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('over 10MB', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limit_raises_provider_rate_limited_exception(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => self::API_KEY], 429),
        ]);

        try {
            $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
            $this->fail('A 429 should be retryable.');
        } catch (ProviderRateLimitedException $exception) {
            $this->assertSame('Gemini judge returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function test_other_http_errors_fail_without_exposing_the_api_key(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('keyframes/shot_1.png', $this->png());
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(self::API_KEY, 500),
        ]);

        try {
            $this->judge()->judgeImage('keyframes/shot_1.png', 'Shot 1: neon alley');
            $this->fail('A provider error should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini judge returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    private function judge(): GeminiJudge
    {
        return new GeminiJudge(self::API_KEY, 'gemini-3.5-flash-lite', 5);
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

    private function fakeGemini(string $text): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
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
}
