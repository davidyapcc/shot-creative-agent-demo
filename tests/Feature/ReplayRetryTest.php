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
    $this->assertSame([
      ['prompt' => 'Shot 1: Wide establishing shot setting the scene. 15s product teaser, rainy Tokyo, neon', 'reason' => 'Left hand has six fingers.'],
    ], $shot->metadata['prompt_attempts']['image']);

    $this->assertCount(1, $brief->shots[1]->judgements->where('stage', 'image'));
    $this->assertCount(1, $brief->shots[2]->judgements->where('stage', 'image'));
    $this->assertNotNull($brief->final_video_path);

    $this->assertSame(21, $brief->budget_used);
    $this->assertCount(21, $brief->metadata['budget_ledger']);
    $this->assertSame(
      ['agent', 'audio', 'image', 'judge', 'video'],
      collect($brief->metadata['budget_ledger'])->pluck('provider')->unique()->sort()->values()->all(),
    );
  }
}
