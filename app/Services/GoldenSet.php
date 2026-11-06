<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GoldenClip;
use App\Support\JsonFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoldenSet
{
    /** The set name stored on an eval run and passed to `--set`. */
    public const NAME = 'golden';

    /** Seeded clips are solid colours, one per clip, cycling if the set grows. */
    private const CLIP_COLORS = ['red', 'green', 'blue', 'yellow', 'purple', 'orange'];

    private const CLIP_SIZE = '160x120';

    private const CLIP_SECONDS = 1;

    private const RENDER_TIMEOUT_SECONDS = 15;

    /**
     * @return list<GoldenClip>
     */
    public static function load(string $path): array
    {
        return array_map(
            fn (mixed $entry): GoldenClip => GoldenClip::fromArray($entry),
            array_values(JsonFile::decode($path, 'Golden set')),
        );
    }

    /**
     * Write any missing clip under the existing bucket. Safe to call again.
     *
     * @param  list<GoldenClip>  $clips
     */
    public static function seed(array $clips): void
    {
        foreach ($clips as $index => $clip) {
            if (Storage::disk('s3')->exists($clip->path)) {
                continue;
            }

            Storage::disk('s3')->put($clip->path, self::render($index));
        }
    }

    private static function render(int $index): string
    {
        $color = self::CLIP_COLORS[$index % count(self::CLIP_COLORS)];
        $temp = tempnam(sys_get_temp_dir(), 'golden');
        $video = $temp.'.mp4';
        rename($temp, $video);

        $process = Process::timeout(self::RENDER_TIMEOUT_SECONDS)->run([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-i', 'color=c='.$color.':s='.self::CLIP_SIZE.':d='.self::CLIP_SECONDS,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-t', (string) self::CLIP_SECONDS,
            $video,
        ]);

        $bytes = ($process->successful() && is_file($video)) ? (string) file_get_contents($video) : '';

        if (is_file($video)) {
            unlink($video);
        }

        if ($bytes === '') {
            throw new RuntimeException('Could not generate a golden clip: '.$process->errorOutput());
        }

        return $bytes;
    }
}
