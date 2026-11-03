<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Events\BriefStateChanged;
use App\Enums\ShotState;
use App\Jobs\GenerateAudioJob;
use App\Jobs\MuxFinalVideoJob;
use App\Jobs\PlanBriefJob;
use App\Jobs\ProcessKeyframesJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Shot;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WalkingSkeletonTest extends TestCase
{
  use RefreshDatabase;

  public function test_brief_accepted_and_reaches_done_on_mock_providers(): void
  {
    Storage::fake('s3');

    $response = $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ]);

    $response->assertStatus(202)
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.state', BriefState::Pending->value);

    $briefId = $response->json('data.brief.id');

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
      Storage::disk('s3')->assertExists($shot->keyframe_path);
      Storage::disk('s3')->assertExists($shot->video_path);
      $this->assertCount(2, $shot->judgements);
    }

    $this->assertDatabaseCount('judgements', 6);

    $getResponse = $this->getJson("/briefs/{$briefId}");
    $getResponse->assertStatus(200)
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.brief.state', BriefState::Done->value)
      ->assertJsonCount(3, 'data.brief.shots')
      ->assertJsonPath('data.brief.shots.0.state', ShotState::Done->value)
      ->assertJsonPath('data.brief.shots.1.state', ShotState::Done->value)
      ->assertJsonPath('data.brief.shots.2.state', ShotState::Done->value);
  }

  public function test_brief_transitions_through_each_pipeline_stage_sequentially(): void
  {
    Queue::fake([
      ProcessKeyframesJob::class,
      RenderShotsJob::class,
      GenerateAudioJob::class,
      MuxFinalVideoJob::class,
    ]);

    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Pending,
    ]);

    $this->assertSame(BriefState::Pending, $brief->state);

    // Stage 1: Planning
    $agent = app(AgentInterface::class);
    $planJob = new PlanBriefJob($brief->id);
    $planJob->handle($agent);

    $brief->refresh();
    $this->assertSame(BriefState::Keyframes, $brief->state);
    $this->assertCount(3, $brief->shots);
    $this->assertSame(ShotState::Pending, $brief->shots->first()->state);
    Queue::assertPushed(ProcessKeyframesJob::class);

    // Stage 2: Keyframes
    $imageProvider = app(ImageProviderInterface::class);
    $judge = app(JudgeInterface::class);
    $keyframesJob = new ProcessKeyframesJob($brief->id);
    $keyframesJob->handle($imageProvider, $judge);

    $brief->refresh();
    $this->assertSame(BriefState::Rendering, $brief->state);
    $this->assertSame(ShotState::JudgingImage, $brief->shots->first()->state);
    Queue::assertPushed(RenderShotsJob::class);

    // Stage 3: Rendering
    $videoProvider = app(VideoProviderInterface::class);
    $renderJob = new RenderShotsJob($brief->id);
    $renderJob->handle($videoProvider);

    $brief->refresh();
    $this->assertSame(BriefState::Audio, $brief->state);
    $this->assertSame(ShotState::Done, $brief->shots->first()->state);
    Queue::assertPushed(GenerateAudioJob::class);

    // Stage 4: Audio
    $audioJob = new GenerateAudioJob($brief->id);
    $audioJob->handle();

    $brief->refresh();
    $this->assertSame(BriefState::Muxing, $brief->state);
    $this->assertNotNull($brief->audio_path);
    Queue::assertPushed(MuxFinalVideoJob::class);

    // Stage 5: Muxing -> Done
    $muxJob = new MuxFinalVideoJob($brief->id);
    $muxJob->handle();

    $brief->refresh();
    $this->assertSame(BriefState::Done, $brief->state);
    $this->assertNotNull($brief->final_video_path);
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

  public function test_job_failure_also_fails_unfinished_shots_and_keeps_done_shots(): void
  {
    $brief = Brief::create([
      'prompt' => '15s product teaser, rainy Tokyo, neon',
      'state' => BriefState::Rendering,
    ]);
    $inFlightShot = $this->createShot($brief, 1, ShotState::GeneratingVideo);
    $doneShot = $this->createShot($brief, 2, ShotState::Done);

    (new RenderShotsJob($brief->id))->failed(new Exception('Render provider timed out'));

    $this->assertSame(BriefState::Failed, $brief->refresh()->state);
    $this->assertSame(ShotState::Failed, $inFlightShot->refresh()->state);
    $this->assertSame(ShotState::Done, $doneShot->refresh()->state);
  }

  public function test_brief_emits_state_changed_event_for_every_pipeline_stage_in_order(): void
  {
    Event::fake([BriefStateChanged::class]);

    $this->postJson('/briefs', [
      'prompt' => '15s product teaser, rainy Tokyo, neon',
    ])->assertStatus(202);

    $transitions = Event::dispatched(BriefStateChanged::class)
      ->map(fn (array $args) => [$args[0]->from, $args[0]->to])
      ->all();

    $this->assertSame([
      [BriefState::Pending, BriefState::Planning],
      [BriefState::Planning, BriefState::Keyframes],
      [BriefState::Keyframes, BriefState::Rendering],
      [BriefState::Rendering, BriefState::Audio],
      [BriefState::Audio, BriefState::Muxing],
      [BriefState::Muxing, BriefState::Done],
    ], $transitions);
  }

  private function createShot(Brief $brief, int $sequence, ShotState $state): Shot
  {
    return Shot::create([
      'brief_id' => $brief->id,
      'sequence' => $sequence,
      'prompt' => "Shot {$sequence}: neon alley",
      'state' => $state,
    ]);
  }
}
