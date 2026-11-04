<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Models\Brief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReplayRetryTest extends TestCase
{
  use RefreshDatabase;

  public function test_replay_fixture_fails_shot_one_then_passes_the_rewrite_within_budget(): void
  {
    Storage::fake('s3');

    $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ])->assertStatus(202);

    $brief = Brief::with('shots.judgements')->firstOrFail();
    $shot = $brief->shots[0];
    $imageJudgements = $shot->judgements->where('stage', 'image')->values();

    $this->assertSame(BriefState::Done, $brief->state);
    $this->assertSame(ShotState::Done, $shot->state);
    $this->assertCount(2, $imageJudgements);
    $this->assertSame('fail', $imageJudgements[0]->verdict);
    $this->assertSame(['anatomy'], $imageJudgements[0]->hard_fail_tags);
    $this->assertSame('Left hand has six fingers.', $imageJudgements[0]->reason);
    $this->assertSame('pass', $imageJudgements[1]->verdict);
    $this->assertStringStartsWith('Shot 1:', $shot->prompt);
    $this->assertStringContainsString('Simplify only this failure', $shot->prompt);
    $this->assertSame(2, $shot->metadata['step_attempts']['image']);
    $this->assertSame(1, $shot->metadata['step_attempts']['video']);
    $this->assertSame(3, $shot->attempts);
    $this->assertSame([
      ['prompt' => 'Shot 1: Wide establishing shot setting the scene. 15s product teaser, rainy Tokyo, neon', 'reason' => 'Left hand has six fingers.'],
    ], $shot->metadata['prompt_attempts']['image']);

    $this->assertCount(1, $brief->shots[1]->judgements->where('stage', 'image'));
    $this->assertCount(1, $brief->shots[2]->judgements->where('stage', 'image'));
    $this->assertNotNull($brief->final_video_path);

    // Shot 1's image retry costs 3 units (rewrite, regenerate, re-judge) and
    // Shot 2's video retry costs 4 (rewrite, submit, poll, re-judge), on top
    // of the 18 a brief with no retries uses.
    $this->assertSame(25, $brief->budget_used);
    $this->assertCount(25, $brief->metadata['budget_ledger']);
    $this->assertSame(
      ['agent', 'audio', 'image', 'judge', 'video'],
      collect($brief->metadata['budget_ledger'])->pluck('provider')->unique()->sort()->values()->all(),
    );
  }

  public function test_replay_fixture_fails_shot_two_video_then_passes_the_rewrite(): void
  {
    // Arrange
    Storage::fake('s3');

    // Act
    $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ])->assertStatus(202);

    // Assert
    $brief = Brief::with('shots.judgements')->firstOrFail();
    $shot = $brief->shots[1];
    $videoJudgements = $shot->judgements->where('stage', 'video')->values();

    $this->assertSame(BriefState::Done, $brief->state);
    $this->assertSame(ShotState::Done, $shot->state);
    $this->assertCount(2, $videoJudgements);
    $this->assertSame('fail', $videoJudgements[0]->verdict);
    $this->assertSame(['temporal_flicker'], $videoJudgements[0]->hard_fail_tags);
    $this->assertSame('pass', $videoJudgements[1]->verdict);
    $this->assertStringContainsString('Simplify only this failure', $shot->prompt);
    $this->assertSame(1, $shot->metadata['step_attempts']['image']);
    $this->assertSame(2, $shot->metadata['step_attempts']['video']);
    $this->assertSame(3, $shot->attempts);
    $this->assertCount(1, $brief->shots[0]->judgements->where('stage', 'video'));
    $this->assertCount(1, $brief->shots[2]->judgements->where('stage', 'video'));
  }
}
