<?php

declare(strict_types=1);

namespace App\Services\Judge;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads shot media and prepares it for a judge: inline video under 10MB, or ordered frames.
 */
class JudgeMedia
{
    public const MAX_INLINE_BYTES = 10 * 1024 * 1024;

    private const FFMPEG_TIMEOUT_SECONDS = 30;

    public function read(string $path): string
    {
        $bytes = Storage::disk('s3')->get($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException("Judge media is missing at [{$path}].");
        }

        return $bytes;
    }

    public function imageMime(string $bytes, string $path): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    /**
     * Bytes safe to send as inline video. Clips already under 10MB are unchanged.
     */
    public function inlineVideo(string $path): string
    {
        $bytes = $this->read($path);

        if (strlen($bytes) <= self::MAX_INLINE_BYTES) {
            return $bytes;
        }

        $compressed = $this->inWorkspace($bytes, function (string $directory): string {
            $output = $directory.'/compressed.mp4';

            $this->ffmpeg([
                'ffmpeg', '-y',
                '-i', $directory.'/clip.mp4',
                '-c:v', 'libx264',
                '-crf', '32',
                '-preset', 'veryfast',
                '-an',
                '-movflags', '+faststart',
                $output,
            ], 'Gemini judge could not compress the clip.');

            return $this->readLocal($output, 'Gemini judge could not compress the clip.');
        });

        if (strlen($compressed) > self::MAX_INLINE_BYTES) {
            throw new RuntimeException('Gemini judge clip is still over 10MB after compression.');
        }

        return $compressed;
    }

    /**
     * Consecutive JPEG frames in time order, never more than the cap.
     *
     * @return list<string>
     */
    public function sampleFrames(string $path, int $fps, int $maxFrames): array
    {
        return $this->inWorkspace($this->read($path), function (string $directory) use ($fps, $maxFrames): array {
            $this->ffmpeg([
                'ffmpeg', '-y',
                '-i', $directory.'/clip.mp4',
                '-vf', 'fps='.$fps,
                '-frames:v', (string) $maxFrames,
                $directory.'/frame_%04d.jpg',
            ], 'OpenAI judge could not sample frames from the clip.');

            $frames = glob($directory.'/frame_*.jpg') ?: [];
            sort($frames, SORT_STRING);

            $images = [];

            foreach ($frames as $frame) {
                $image = file_get_contents($frame);

                if (! is_string($image) || $image === '') {
                    continue;
                }

                $images[] = $image;

                if (count($images) >= $maxFrames) {
                    break;
                }
            }

            if ($images === []) {
                throw new RuntimeException('OpenAI judge could not sample frames from the clip.');
            }

            return $images;
        });
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function inWorkspace(string $bytes, callable $callback): mixed
    {
        $directory = sys_get_temp_dir().'/judge_media_'.bin2hex(random_bytes(8));

        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Judge could not prepare a workspace for the clip.');
        }

        try {
            file_put_contents($directory.'/clip.mp4', $bytes);

            return $callback($directory);
        } finally {
            $this->deleteWorkspace($directory);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function ffmpeg(array $command, string $failure): void
    {
        $process = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run($command);

        if (! $process->successful()) {
            throw new RuntimeException($failure.' '.$process->errorOutput());
        }
    }

    private function readLocal(string $path, string $failure): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException($failure);
        }

        return $bytes;
    }

    private function deleteWorkspace(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
