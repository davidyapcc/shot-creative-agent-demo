<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\GoldenClip;
use App\Enums\JudgementVerdict;
use App\Services\GoldenSet;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GoldenSetTest extends TestCase
{
    public function test_unknown_tag_names_the_clip(): void
    {
        $path = $this->writeGolden([[
            'id' => 'flicker-neon',
            'path' => 'evals/golden/flicker-neon.mp4',
            'prompt' => 'A neon sign flickers.',
            'verdict' => 'fail',
            'tags' => ['melted'],
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('flicker-neon');

        try {
            GoldenSet::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_unknown_verdict_names_the_clip(): void
    {
        $path = $this->writeGolden([[
            'id' => 'warp-car',
            'path' => 'evals/golden/warp-car.mp4',
            'prompt' => 'A car keeps its shape.',
            'verdict' => 'maybe',
            'tags' => [],
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('warp-car');

        try {
            GoldenSet::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_recorded_flag_defaults_to_false_and_rejects_non_boolean(): void
    {
        $validPath = $this->writeGolden([
            [
                'id' => 'placeholder-clip',
                'path' => 'evals/golden/placeholder.mp4',
                'prompt' => 'A steady street.',
                'verdict' => 'pass',
                'tags' => [],
            ],
            [
                'id' => 'recorded-clip',
                'path' => 'evals/golden/recorded.mp4',
                'prompt' => 'A Veo recorded clip.',
                'verdict' => 'pass',
                'tags' => [],
                'recorded' => true,
            ],
        ]);

        try {
            $clips = GoldenSet::load($validPath);
            $this->assertFalse($clips[0]->recorded);
            $this->assertTrue($clips[1]->recorded);
        } finally {
            unlink($validPath);
        }

        $invalidPath = $this->writeGolden([[
            'id' => 'bad-recorded-flag',
            'path' => 'evals/golden/bad.mp4',
            'prompt' => 'Bad recorded value.',
            'verdict' => 'pass',
            'tags' => [],
            'recorded' => 'yes',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bad-recorded-flag');

        try {
            GoldenSet::load($invalidPath);
        } finally {
            unlink($invalidPath);
        }
    }

    public function test_seed_writes_nothing_for_a_missing_recorded_clip(): void
    {
        Storage::fake('s3');
        Process::fake();

        $recorded = new GoldenClip(
            id: 'veo-clip',
            path: 'evals/golden/veo-clip.mp4',
            prompt: 'A neon street.',
            verdict: JudgementVerdict::Pass,
            tags: [],
            recorded: true,
        );

        GoldenSet::seed([$recorded]);

        Storage::disk('s3')->assertMissing('evals/golden/veo-clip.mp4');
        Process::assertNothingRan();
    }

    public function test_seed_still_creates_missing_placeholder_clips_alongside_recorded_clips(): void
    {
        Storage::fake('s3');

        $placeholder = new GoldenClip(
            id: 'placeholder-clip',
            path: 'evals/golden/placeholder-clip.mp4',
            prompt: 'A quiet street.',
            verdict: JudgementVerdict::Pass,
            tags: [],
            recorded: false,
        );
        $recorded = new GoldenClip(
            id: 'veo-clip',
            path: 'evals/golden/veo-clip.mp4',
            prompt: 'A neon street.',
            verdict: JudgementVerdict::Pass,
            tags: [],
            recorded: true,
        );

        GoldenSet::seed([$placeholder, $recorded]);

        Storage::disk('s3')->assertExists('evals/golden/placeholder-clip.mp4');
        Storage::disk('s3')->assertMissing('evals/golden/veo-clip.mp4');
    }

    public function test_assert_present_passes_when_all_clips_exist_and_names_every_missing_clip(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('evals/golden/present.mp4', 'real-mp4-bytes');

        $present = new GoldenClip('present-clip', 'evals/golden/present.mp4', 'Present.', JudgementVerdict::Pass, [], true);
        $missingA = new GoldenClip('missing-a', 'evals/golden/missing-a.mp4', 'Missing A.', JudgementVerdict::Pass, [], true);
        $missingB = new GoldenClip('missing-b', 'evals/golden/missing-b.mp4', 'Missing B.', JudgementVerdict::Fail, ['object_warp'], true);

        GoldenSet::assertPresent([$present]);

        try {
            GoldenSet::assertPresent([$present, $missingA, $missingB]);
            $this->fail('Expected RuntimeException when clips are missing.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringNotContainsString('present-clip', $message);
            $this->assertStringContainsString('missing-a', $message);
            $this->assertStringContainsString('evals/golden/missing-a.mp4', $message);
            $this->assertStringContainsString('missing-b', $message);
            $this->assertStringContainsString('evals/golden/missing-b.mp4', $message);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     */
    private function writeGolden(array $clips): string
    {
        $path = tempnam(sys_get_temp_dir(), 'golden');
        file_put_contents($path, json_encode($clips));

        return $path;
    }
}
