<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BriefApiTest extends TestCase
{
  use RefreshDatabase;

  public function test_post_briefs_validates_prompt_required(): void
  {
    $response = $this->postJson('/briefs', []);

    $response->assertStatus(422)
      ->assertJsonValidationErrors(['prompt']);
  }

  public function test_post_briefs_persists_brief_and_only_enqueues_job(): void
  {
    Queue::fake();

    $response = $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ]);

    $response->assertStatus(202)
      ->assertJsonPath('brief.prompt', '15s product teaser, rainy Tokyo, neon')
      ->assertJsonPath('brief.state', BriefState::Pending->value);

    $this->assertDatabaseHas('briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Pending->value,
    ]);

    Queue::assertPushed(PlanBriefJob::class, function (PlanBriefJob $job) use ($response)
    {
      return $job->briefId === $response->json('brief.id');
    });
  }

  public function test_get_brief_returns_brief_and_shots(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Planning,
    ]);

    $shot1 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon sign reflecting on puddle',
      'state' => ShotState::Pending,
    ]);

    $shot2 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 2,
      'prompt' => 'Shot 2: Close up of product on rainy asphalt',
      'state' => ShotState::Pending,
    ]);

    $response = $this->getJson("/briefs/{$brief->id}");

    $response->assertStatus(200)
      ->assertJsonPath('brief.id', $brief->id)
      ->assertJsonPath('brief.state', BriefState::Planning->value)
      ->assertJsonCount(2, 'brief.shots')
      ->assertJsonPath('brief.shots.0.sequence', 1)
      ->assertJsonPath('brief.shots.1.sequence', 2);
  }

  public function test_get_brief_not_found(): void
  {
    $response = $this->getJson('/briefs/9999');

    $response->assertStatus(404);
  }
}
