<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Streams pipeline artifacts.
 *
 * The demo has no logins, so this route is public on purpose.
 * Paths are limited to known prefixes and extensions, and the route is rate limited.
 */
class MediaController extends Controller
{
  private const ALLOWED_PREFIXES = ['keyframes/', 'videos/', 'audio/', 'final/'];
  private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'mp4', 'mp3', 'wav'];

  public function show(string $path): Response
  {
    // Sanitize path against directory traversal
    $normalized = str_replace('\\', '/', $path);
    if (str_contains($normalized, '..') || str_starts_with($normalized, '/'))
    {
      abort(404);
    }

    $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, true))
    {
      abort(404);
    }

    $isAllowedPrefix = false;
    foreach (self::ALLOWED_PREFIXES as $prefix)
    {
      if (str_starts_with($normalized, $prefix))
      {
        $isAllowedPrefix = true;
        break;
      }
    }

    if (!$isAllowedPrefix)
    {
      abort(404);
    }

    $disk = Storage::disk('s3');

    if (!$disk->exists($normalized))
    {
      abort(404);
    }

    $mimeType = $disk->mimeType($normalized) ?: match ($extension)
    {
      'png' => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      'mp4' => 'video/mp4',
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      default => 'application/octet-stream',
    };

    $content = $disk->get($normalized);

    return response($content, 200, [
      'Content-Type' => $mimeType,
      'Cache-Control' => 'private, max-age=3600, no-transform',
    ]);
  }
}
