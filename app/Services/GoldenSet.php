<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Judge\JudgeRubric;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoldenSet
{
    /**
     * @return list<array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}>
     */
    public static function load(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("Golden set is missing: {$path}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Golden set is not valid JSON: {$path}");
        }

        $clips = [];

        foreach ($decoded as $entry) {
            $clips[] = self::clip($entry);
        }

        return $clips;
    }

    /**
     * Write any missing clip under the existing bucket. Safe to call again.
     *
     * @param  list<array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}>  $clips
     */
    public static function seed(array $clips): void
    {
        foreach ($clips as $index => $clip) {
            if (Storage::disk('s3')->exists($clip['path'])) {
                continue;
            }

            Storage::disk('s3')->put($clip['path'], self::render($index));
        }
    }

    private static function render(int $index): string
    {
        $colors = ['red', 'green', 'blue', 'yellow', 'purple', 'orange'];
        $color = $colors[$index % count($colors)];
        $temp = tempnam(sys_get_temp_dir(), 'golden');
        $video = $temp.'.mp4';
        rename($temp, $video);

        $process = Process::timeout(15)->run([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-i', "color=c={$color}:s=160x120:d=1",
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-t', '1',
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

    /**
     * @return array{id: string, path: string, prompt: string, verdict: string, tags: list<string>}
     */
    private static function clip(mixed $entry): array
    {
        if (! is_array($entry)) {
            throw new RuntimeException('Golden set has an entry that is not a clip.');
        }

        $id = $entry['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Golden set has a clip with no id.');
        }

        $path = $entry['path'] ?? null;
        $prompt = $entry['prompt'] ?? null;
        $verdict = $entry['verdict'] ?? null;
        $tags = $entry['tags'] ?? null;

        if (! is_string($path) || $path === '' || ! is_string($prompt) || $prompt === '') {
            throw new RuntimeException("Golden clip {$id} is missing a path or prompt.");
        }

        if (! is_string($verdict) || ! in_array($verdict, ['pass', 'fail'], true)) {
            $shown = is_string($verdict) ? $verdict : get_debug_type($verdict);

            throw new RuntimeException("Golden clip {$id} has an unknown verdict [{$shown}].");
        }

        if (! is_array($tags)) {
            throw new RuntimeException("Golden clip {$id} has tags that are not a list.");
        }

        $parsedTags = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) || ! in_array($tag, JudgeRubric::HARD_FAIL_TAGS, true)) {
                $shown = is_string($tag) ? $tag : get_debug_type($tag);

                throw new RuntimeException("Golden clip {$id} has an unknown tag [{$shown}].");
            }

            $parsedTags[] = $tag;
        }

        return [
            'id' => $id,
            'path' => $path,
            'prompt' => $prompt,
            'verdict' => $verdict,
            'tags' => $parsedTags,
        ];
    }
}
