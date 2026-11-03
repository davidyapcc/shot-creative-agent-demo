<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\VideoProviderInterface;
use App\DTOs\VideoRenderResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FfmpegMotionVideoProvider implements VideoProviderInterface
{
  public function submit(string $prompt, string $keyframePath, array $options = []): string
  {
    $renderId = 'ffmpeg_render_' . Str::random(16);

    Cache::put("ffmpeg_motion_{$renderId}", [
      'keyframe_path' => $keyframePath,
      'prompt' => $prompt,
      'status' => 'pending',
    ], 3600);

    return $renderId;
  }

  public function poll(string $renderId): VideoRenderResult
  {
    $cached = Cache::get("ffmpeg_motion_{$renderId}");

    if (!is_array($cached))
    {
      return VideoRenderResult::failed("Render ID [{$renderId}] not found.");
    }

    $keyframePath = $cached['keyframe_path'] ?? '';

    // Retrieve image content from s3 or local disk
    $imageBytes = null;
    if (Storage::disk('s3')->exists($keyframePath))
    {
      $imageBytes = Storage::disk('s3')->get($keyframePath);
    }
    elseif (file_exists($keyframePath))
    {
      $imageBytes = file_get_contents($keyframePath);
    }

    if ($imageBytes === null || $imageBytes === '')
    {
      // Fallback: use replay video bytes if keyframe not found
      $replay = new ReplayVideoProvider(pendingPolls: 0);

      return $replay->poll($renderId);
    }

    $tempImage = tempnam(sys_get_temp_dir(), 'kp_') . '.png';
    $tempVideo = tempnam(sys_get_temp_dir(), 'vid_') . '.mp4';

    file_put_contents($tempImage, $imageBytes);

    $process = Process::run([
      'ffmpeg',
      '-y',
      '-loop', '1',
      '-i', $tempImage,
      '-c:v', 'libx264',
      '-t', '2',
      '-pix_fmt', 'yuv420p',
      '-vf', "scale=640:360,zoompan=z='min(zoom+0.0015,1.2)':d=50:s=640x360",
      $tempVideo,
    ]);

    $videoBytes = '';
    if ($process->successful() && file_exists($tempVideo))
    {
      $videoBytes = (string) file_get_contents($tempVideo);
    }

    @unlink($tempImage);
    @unlink($tempVideo);

    if ($videoBytes === '')
    {
      $replay = new ReplayVideoProvider(pendingPolls: 0);

      return $replay->poll($renderId);
    }

    return VideoRenderResult::completed($videoBytes);
  }
}
