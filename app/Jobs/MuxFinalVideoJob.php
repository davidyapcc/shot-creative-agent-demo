<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MuxFinalVideoJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  private const FFMPEG_TIMEOUT_SECONDS = 30;

  public function __construct(
    public readonly int $briefId,
  )
  {
  }

  public function handle(): void
  {
    $brief = Brief::with('shots')->findOrFail($this->briefId);

    if ($brief->state !== BriefState::Muxing)
    {
      return;
    }

    $clips = [];

    foreach ($brief->shots as $shot)
    {
      if ($shot->state !== ShotState::Done || $shot->video_path === null)
      {
        continue;
      }

      $clips[] = $this->readObject($shot->video_path, "Passing clip missing at [{$shot->video_path}].");
    }

    if ($clips === [])
    {
      throw new RuntimeException('No passing clips to mux.');
    }

    $voiceoverPath = $brief->audio_path;
    $soundEffectPath = $brief->metadata['sound_effect_path'] ?? null;

    if (!is_string($voiceoverPath) || !is_string($soundEffectPath))
    {
      throw new RuntimeException('Brief is missing its voiceover or sound effect.');
    }

    $voiceover = $this->readObject($voiceoverPath, 'Brief voiceover is missing from storage.');
    $soundEffect = $this->readObject($soundEffectPath, 'Brief sound effect is missing from storage.');

    $mp4 = $this->mux($clips, $voiceover, $soundEffect);
    $finalPath = "final/brief_{$brief->id}_final.mp4";

    Storage::disk('s3')->put($finalPath, $mp4);
    $brief->update([
      'final_video_path' => $finalPath,
    ]);
    $brief->transitionTo(BriefState::Done);
  }

  /**
   * @param list<string> $clips
   */
  private function mux(array $clips, string $voiceover, string $soundEffect): string
  {
    $directory = sys_get_temp_dir() . '/mux_' . uniqid('', true);

    if (!mkdir($directory) && !is_dir($directory))
    {
      throw new RuntimeException('Unable to create a temporary mux directory.');
    }

    try
    {
      $command = ['ffmpeg', '-y'];
      $labels = '';

      foreach ($clips as $index => $bytes)
      {
        $path = $directory . "/clip_{$index}.mp4";
        file_put_contents($path, $bytes);
        $command[] = '-i';
        $command[] = $path;
        $labels .= "[{$index}:v]";
      }

      $voiceoverPath = $directory . '/voiceover.wav';
      $soundEffectPath = $directory . '/sfx.wav';
      $outputPath = $directory . '/final.mp4';
      file_put_contents($voiceoverPath, $voiceover);
      file_put_contents($soundEffectPath, $soundEffect);

      $clipCount = count($clips);
      $voiceIndex = $clipCount;
      $soundEffectIndex = $clipCount + 1;
      $filter = $labels
        . "concat=n={$clipCount}:v=1:a=0[v];"
        . "[{$voiceIndex}:a][{$soundEffectIndex}:a]amix=inputs=2:duration=longest[a]";

      $command = [
        ...$command,
        '-i', $voiceoverPath,
        '-i', $soundEffectPath,
        '-filter_complex', $filter,
        '-map', '[v]',
        '-map', '[a]',
        '-c:v', 'libx264',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        $outputPath,
      ];

      $process = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run($command);

      if (!$process->successful() || !is_file($outputPath))
      {
        throw new RuntimeException('ffmpeg mux failed: ' . $process->errorOutput());
      }

      $mp4 = file_get_contents($outputPath);

      if (!is_string($mp4) || $mp4 === '')
      {
        throw new RuntimeException('ffmpeg mux produced an empty file.');
      }

      return $mp4;
    }
    finally
    {
      $this->deleteDirectory($directory);
    }
  }

  private function readObject(string $path, string $message): string
  {
    $bytes = Storage::disk('s3')->get($path);

    if (!is_string($bytes) || $bytes === '')
    {
      throw new RuntimeException($message);
    }

    return $bytes;
  }

  private function deleteDirectory(string $directory): void
  {
    if (!is_dir($directory))
    {
      return;
    }

    $files = scandir($directory);

    if ($files === false)
    {
      return;
    }

    foreach ($files as $file)
    {
      if ($file === '.' || $file === '..')
      {
        continue;
      }

      $path = $directory . '/' . $file;

      if (is_file($path))
      {
        unlink($path);
      }
    }

    rmdir($directory);
  }
}
