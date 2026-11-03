<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;
use Exception;
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
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.state', BriefState::Pending->value);

    $briefId = $response->json('data.brief.id');

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
    }

    // 2. GET /briefs/{id} reflects the completed state
    $getResponse = $this->getJson("/briefs/{$briefId}");
    $getResponse->assertStatus(200)
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.state', BriefState::Done->value)
      ->assertJsonCount(3, 'data.brief.shots')
      ->assertJsonPath('data.brief.shots.0.state', ShotState::Done->value)
      ->assertJsonPath('data.brief.shots.1.state', ShotState::Done->value)
      ->assertJsonPath('data.brief.shots.2.state', ShotState::Done->value);
  }

  public function test_job_failure_transitions_brief_to_failed(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Pending,
    ]);

    $job = new PlanBriefJob($brief->id);
    $job->failed(new Exception('Something went wrong during generation'));

    $brief->refresh();
    $this->assertSame(BriefState::Failed, $brief->state);
  }
}
