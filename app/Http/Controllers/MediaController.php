<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
  public function show(string $path): Response
  {
    // Sanitize path against directory traversal
    $normalized = str_replace('\\', '/', $path);
    if (str_contains($normalized, '..') || str_starts_with($normalized, '/'))
    {
      abort(404);
    }

    $disk = Storage::disk('s3');

    if (!$disk->exists($normalized))
    {
      abort(404);
    }

    $mimeType = $disk->mimeType($normalized) ?: match (pathinfo($normalized, PATHINFO_EXTENSION))
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
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }
}
