<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AgentInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class BriefApiTest extends TestCase
{
  use RefreshDatabase;

  private const RATE_LIMIT_PER_MINUTE = 60;

  public function test_post_briefs_validates_prompt_required(): void
  {
    $response = $this->postJson('/briefs', []);

    $this->assertApiError($response, 422, 'validation_failed');
    $response->assertJsonStructure(['error' => ['fields' => ['prompt']]]);
  }

  public function test_post_briefs_validates_prompt_max_length(): void
  {
    $response = $this->postJson('/briefs', [
      'prompt' => str_repeat('a', 1001),
    ]);

    $this->assertApiError($response, 422, 'validation_failed');
    $response->assertJsonStructure(['error' => ['fields' => ['prompt']]]);
  }

  public function test_post_briefs_persists_brief_and_only_enqueues_job(): void
  {
    Queue::fake();

    $response = $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ]);

    $response->assertStatus(202)
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.prompt', '15s product teaser, rainy Tokyo, neon')
      ->assertJsonPath('data.brief.state', BriefState::Pending->value);

    $this->assertDatabaseHas('briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Pending->value,
    ]);

    Queue::assertPushed(PlanBriefJob::class, function (PlanBriefJob $job) use ($response): bool
    {
      return $job->briefId === $response->json('data.brief.id');
    });
  }

  public function test_get_brief_returns_brief_and_shots(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Planning,
    ]);

    Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon sign reflecting on puddle',
      'state' => ShotState::Pending,
    ]);

    Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 2,
      'prompt' => 'Shot 2: Close up of product on rainy asphalt',
      'state' => ShotState::Pending,
    ]);

    $response = $this->getJson("/briefs/{$brief->id}");

    $response->assertStatus(200)
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.id', $brief->id)
      ->assertJsonPath('data.brief.state', BriefState::Planning->value)
      ->assertJsonCount(2, 'data.brief.shots')
      ->assertJsonPath('data.brief.shots.0.sequence', 1)
      ->assertJsonPath('data.brief.shots.1.sequence', 2);
  }

  public function test_get_brief_includes_shot_judgements(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Rendering,
    ]);

    $shot = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1: Neon sign reflecting on puddle',
      'state' => ShotState::JudgingVideo,
    ]);

    Judgement::create([
      'shot_id' => $shot->id,
      'stage' => 'video',
      'scores' => ['prompt_adherence' => 5],
      'hard_fail_tags' => [],
      'reason' => 'Perfect shot',
      'verdict' => 'pass',
    ]);

    $response = $this->getJson("/briefs/{$brief->id}");

    $response->assertStatus(200)
      ->assertJsonCount(1, 'data.brief.shots.0.judgements')
      ->assertJsonPath('data.brief.shots.0.judgements.0.verdict', 'pass')
      ->assertJsonPath('data.brief.shots.0.judgements.0.reason', 'Perfect shot');
  }

  public function test_get_brief_returns_404_when_brief_does_not_exist(): void
  {
    $response = $this->getJson('/briefs/9999');

    $this->assertApiError($response, 404, 'not_found');
  }

  public function test_post_briefs_returns_429_error_shape_when_rate_limited(): void
  {
    Queue::fake();

    for ($attempt = 0; $attempt < self::RATE_LIMIT_PER_MINUTE; $attempt++)
    {
      $this->getJson('/briefs/9999');
    }

    $response = $this->getJson('/briefs/9999');

    $this->assertApiError($response, 429, 'too_many_requests');
  }

  public function test_unexpected_error_returns_500_error_shape_without_leaking_details(): void
  {
    $this->app->bind(AgentInterface::class, fn () => new class implements AgentInterface
    {
      public function planShots(string $prompt): array
      {
        throw new RuntimeException('secret provider failure');
      }

      public function generateStyleAnchor(string $prompt): string
      {
        throw new RuntimeException('secret provider failure');
      }
    });

    $response = $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ]);

    $this->assertApiError($response, 500, 'server_error');
    $this->assertStringNotContainsString('secret provider failure', $response->getContent());
  }

  private function assertApiError(TestResponse $response, int $status, string $code): void
  {
    $response->assertStatus($status)
      ->assertJsonPath('success', false)
      ->assertJsonPath('data', null)
      ->assertJsonPath('meta', null)
      ->assertJsonPath('error.code', $code)
      ->assertJsonStructure(['error' => ['message']]);
  }
}
