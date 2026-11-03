<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaDeliveryTest extends TestCase
{
  public function test_media_route_serves_image_from_s3(): void
  {
    Storage::fake('s3');
    Storage::disk('s3')->put('keyframes/test.png', 'fake-png-content');

    $response = $this->get('/media/keyframes/test.png');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/png');
    $this->assertSame('fake-png-content', $response->getContent());
  }

  public function test_media_route_serves_video_from_s3(): void
  {
    Storage::fake('s3');
    Storage::disk('s3')->put('videos/test.mp4', 'fake-mp4-content');

    $response = $this->get('/media/videos/test.mp4');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'video/mp4');
    $this->assertSame('fake-mp4-content', $response->getContent());
  }

  public function test_media_route_serves_audio_from_s3(): void
  {
    Storage::fake('s3');
    Storage::disk('s3')->put('audio/test.mp3', 'fake-mp3-content');

    $response = $this->get('/media/audio/test.mp3');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'audio/mpeg');
    $this->assertSame('fake-mp3-content', $response->getContent());
  }

  public function test_media_route_returns_404_when_file_not_found(): void
  {
    Storage::fake('s3');

    $response = $this->get('/media/keyframes/missing.png');

    $response->assertStatus(404);
  }

  public function test_media_route_rejects_path_traversal(): void
  {
    Storage::fake('s3');

    $response = $this->get('/media/../secret.txt');

    $response->assertStatus(404);
  }
}
