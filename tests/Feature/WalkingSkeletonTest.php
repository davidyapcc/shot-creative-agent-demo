<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkingSkeletonTest extends TestCase
{
  use RefreshDatabase;

  public function test_brief_accepted_and_reaches_done_on_mock_providers(): void
  {
    // 1. Submit brief through API
    $response = $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ]);

    $response->assertStatus(202)
      ->assertJsonPath('brief.state', BriefState::Pending->value);

    $briefId = $response->json('brief.id');

    // In testing environment with sync queue (or executing queued jobs synchronously),
    // the brief progresses through the pipeline to completion.
    $brief = Brief::with('shots')->findOrFail($briefId);

    $this->assertSame(BriefState::Done, $brief->state);
    $this->assertCount(3, $brief->shots);
    $this->assertNotNull($brief->style_anchor);
    $this->assertNotNull($brief->audio_path);
    $this->assertNotNull($brief->final_video_path);

    foreach ($brief->shots as $shot)
    {
      $this->assertSame(ShotState::Done, $shot->state);
      $this->assertNotNull($shot->keyframe_path);
      $this->assertNotNull($shot->video_path);
      $this->assertCount(2, $shot->judgements);
    }

    // 2. GET /briefs/{id} reflects the completed state
    $getResponse = $this->getJson("/briefs/{$briefId}");
    $getResponse->assertStatus(200)
      ->assertJsonPath('brief.state', BriefState::Done->value)
      ->assertJsonCount(3, 'brief.shots')
      ->assertJsonPath('brief.shots.0.state', ShotState::Done->value)
      ->assertJsonPath('brief.shots.1.state', ShotState::Done->value)
      ->assertJsonPath('brief.shots.2.state', ShotState::Done->value);
  }
}
