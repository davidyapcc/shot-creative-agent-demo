<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GoldenSet;
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
