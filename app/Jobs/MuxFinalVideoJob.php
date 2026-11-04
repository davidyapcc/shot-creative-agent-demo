<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use App\Traits\FailsBriefOnJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MuxFinalVideoJob implements ShouldQueue
{
  use FailsBriefOnJobFailure;
  use Queueable;

  private const FFMPEG_TIMEOUT_SECONDS = 30;

  private const VIDEO_CODEC = 'libx264';

  private const PIXEL_FORMAT = 'yuv420p';

  private const AUDIO_CODEC = 'aac';

  /**
   * The voiceover and the sound effect are mixed into one track, then padded
   * with silence so -shortest ends the file with the last clip.
   */
  private const AUDIO_TRACK_COUNT = 2;

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

    $clips = $this->passingClips($brief);
    [$voiceover, $soundEffect] = $this->audioTracks($brief);

    $mp4 = $this->mux($clips, $voiceover, $soundEffect);
    $finalPath = "final/brief_{$brief->id}_final.mp4";

    Storage::disk('s3')->put($finalPath, $mp4);
    $brief->update([
      'final_video_path' => $finalPath,
    ]);
    $brief->transitionTo(BriefState::Done);
  }

  /**
   * @return list<string>
   */
  private function passingClips(Brief $brief): array
  {
    $clips = $brief->shots
      ->filter(fn ($shot): bool => $shot->state === ShotState::Done && $shot->video_path !== null)
      ->map(fn ($shot): string => $this->readObject($shot->video_path, "Passing clip missing at [{$shot->video_path}]."))
      ->values()
      ->all();

    if ($clips === [])
    {
      throw new RuntimeException('No passing clips to mux.');
    }

    return $clips;
  }

  /**
   * @return array{0: string, 1: string}
   */
  private function audioTracks(Brief $brief): array
  {
    $voiceoverPath = $brief->audio_path;
    $soundEffectPath = $brief->metadata['sound_effect_path'] ?? null;

    if (!is_string($voiceoverPath) || !is_string($soundEffectPath))
    {
      throw new RuntimeException('Brief is missing its voiceover or sound effect.');
    }

    return [
      $this->readObject($voiceoverPath, 'Brief voiceover is missing from storage.'),
      $this->readObject($soundEffectPath, 'Brief sound effect is missing from storage.'),
    ];
  }

  /**
   * @param list<string> $clips
   */
  private function mux(array $clips, string $voiceover, string $soundEffect): string
  {
    $directory = $this->makeWorkspace();

    try
    {
      $clipPaths = array_map(
        fn (int $index, string $bytes): string => $this->writeFile($directory, "clip_{$index}.mp4", $bytes),
        array_keys($clips),
        $clips,
      );
      $voiceoverPath = $this->writeFile($directory, 'voiceover.wav', $voiceover);
      $soundEffectPath = $this->writeFile($directory, 'sfx.wav', $soundEffect);
      $outputPath = $directory . '/final.mp4';

      $this->runFfmpeg($this->buildCommand($clipPaths, $voiceoverPath, $soundEffectPath, $outputPath), $outputPath);

      return $this->readOutput($outputPath);
    }
    finally
    {
      $this->deleteWorkspace($directory);
    }
  }

  /**
   * @param list<string> $clipPaths
   * @return list<string>
   */
  private function buildCommand(array $clipPaths, string $voiceoverPath, string $soundEffectPath, string $outputPath): array
  {
    $clipCount = count($clipPaths);
    $videoLabels = implode('', array_map(fn (int $index): string => "[{$index}:v]", array_keys($clipPaths)));
    $voiceIndex = $clipCount;
    $soundEffectIndex = $clipCount + 1;

    $filter = "{$videoLabels}concat=n={$clipCount}:v=1:a=0[v];"
      . "[{$voiceIndex}:a][{$soundEffectIndex}:a]amix=inputs=" . self::AUDIO_TRACK_COUNT . ':duration=longest,apad[a]';

    $inputs = array_merge(...array_map(fn (string $path): array => ['-i', $path], $clipPaths));

    return [
      'ffmpeg', '-y',
      ...$inputs,
      '-i', $voiceoverPath,
      '-i', $soundEffectPath,
      '-filter_complex', $filter,
      '-map', '[v]',
      '-map', '[a]',
      '-c:v', self::VIDEO_CODEC,
      '-pix_fmt', self::PIXEL_FORMAT,
      '-c:a', self::AUDIO_CODEC,
      '-shortest',
      $outputPath,
    ];
  }

  /**
   * @param list<string> $command
   */
  private function runFfmpeg(array $command, string $outputPath): void
  {
    $process = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run($command);

    if (!$process->successful() || !is_file($outputPath))
    {
      throw new RuntimeException('ffmpeg mux failed: ' . $process->errorOutput());
    }
  }

  private function readOutput(string $outputPath): string
  {
    $mp4 = file_get_contents($outputPath);

    if (!is_string($mp4) || $mp4 === '')
    {
      throw new RuntimeException('ffmpeg mux produced an empty file.');
    }

    return $mp4;
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

  private function makeWorkspace(): string
  {
    $directory = sys_get_temp_dir() . '/mux_' . uniqid('', true);

    if (!mkdir($directory) && !is_dir($directory))
    {
      throw new RuntimeException('Unable to create a temporary mux directory.');
    }

    return $directory;
  }

  private function writeFile(string $directory, string $name, string $bytes): string
  {
    $path = $directory . '/' . $name;

    if (file_put_contents($path, $bytes) === false)
    {
      throw new RuntimeException("Unable to write mux input [{$name}].");
    }

    return $path;
  }

  /**
   * Cleanup runs in a finally block, so a failure here is logged rather than
   * thrown: throwing would hide the mux error that is already on its way out.
   */
  private function deleteWorkspace(string $directory): void
  {
    $paths = glob($directory . '/*');

    if ($paths === false)
    {
      Log::warning('Unable to list the mux workspace for cleanup.', ['directory' => $directory]);

      return;
    }

    foreach ($paths as $path)
    {
      if (is_file($path) && !unlink($path))
      {
        Log::warning('Unable to delete a mux workspace file.', ['path' => $path]);
      }
    }

    if (is_dir($directory) && !rmdir($directory))
    {
      Log::warning('Unable to delete the mux workspace.', ['directory' => $directory]);
    }
  }
}
