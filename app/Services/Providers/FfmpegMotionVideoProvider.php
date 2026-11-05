<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\VideoProviderInterface;
use App\DTOs\VideoRenderResult;
use App\Enums\VideoRenderStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FfmpegMotionVideoProvider implements VideoProviderInterface
{
    private const VIDEO_DURATION_SECONDS = '2';

    private const ZOOMPAN_FILTER = "scale=640:360,zoompan=z='min(zoom+0.0015,1.2)':d=50:s=640x360";

    private const CACHE_TTL_SECONDS = 3600;

    private const FFMPEG_TIMEOUT_SECONDS = 15;

    public function submit(string $prompt, string $keyframePath): string
    {
        if (! Storage::disk('s3')->exists($keyframePath)) {
            throw new InvalidArgumentException("Keyframe not found at S3 path [{$keyframePath}].");
        }

        $renderId = 'ffmpeg_render_'.Str::random(16);

        Cache::put("ffmpeg_motion_{$renderId}", [
            'keyframe_path' => $keyframePath,
            'prompt' => $prompt,
            'poll_count' => 0,
            'status' => VideoRenderStatus::Pending->value,
        ], self::CACHE_TTL_SECONDS);

        return $renderId;
    }

    public function poll(string $renderId): VideoRenderResult
    {
        $cached = Cache::get("ffmpeg_motion_{$renderId}");

        if (! is_array($cached)) {
            return VideoRenderResult::failed("Render ID [{$renderId}] not found.");
        }

        $pollCount = (int) ($cached['poll_count'] ?? 0);

        if ($pollCount === 0) {
            $cached['poll_count'] = 1;
            Cache::put("ffmpeg_motion_{$renderId}", $cached, self::CACHE_TTL_SECONDS);

            return VideoRenderResult::pending();
        }

        $keyframePath = (string) ($cached['keyframe_path'] ?? '');
        $imageBytes = (string) Storage::disk('s3')->get($keyframePath);

        return $this->transcodeImageToVideo($imageBytes);
    }

    private function transcodeImageToVideo(string $imageBytes): VideoRenderResult
    {
        $isPng = str_starts_with($imageBytes, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($imageBytes, "\xFF\xD8\xFF");

        if (! $isPng && ! $isJpeg) {
            return VideoRenderResult::failed('Invalid or corrupt keyframe image data.');
        }

        $tempImage = tempnam(sys_get_temp_dir(), 'kp_').'.png';
        $tempVideo = tempnam(sys_get_temp_dir(), 'vid_').'.mp4';

        file_put_contents($tempImage, $imageBytes);

        $process = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
            'ffmpeg',
            '-y',
            '-loop', '1',
            '-i', $tempImage,
            '-c:v', 'libx264',
            '-t', self::VIDEO_DURATION_SECONDS,
            '-pix_fmt', 'yuv420p',
            '-vf', self::ZOOMPAN_FILTER,
            $tempVideo,
        ]);

        $videoBytes = '';
        $success = $process->successful() && file_exists($tempVideo);

        if ($success) {
            $videoBytes = (string) file_get_contents($tempVideo);
        }

        $this->cleanupTempFiles($tempImage, $tempVideo);

        if (! $success || $videoBytes === '') {
            return VideoRenderResult::failed('FFmpeg motion generation failed: '.$process->errorOutput());
        }

        return VideoRenderResult::completed($videoBytes);
    }

    private function cleanupTempFiles(string $tempImage, string $tempVideo): void
    {
        if (file_exists($tempImage)) {
            unlink($tempImage);
        }

        if (file_exists($tempVideo)) {
            unlink($tempVideo);
        }
    }
}
