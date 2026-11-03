<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\VideoProviderInterface;
use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\AdvanceBriefToAudioJob;
use App\Jobs\GenerateAudioJob;
use App\Jobs\RenderShotsJob;
use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RenderShotsJobTest extends TestCase
{
  use RefreshDatabase;

  public function test_render_shots_job_submits_renders_and_dispatches_parallel_batch(): void
  {
    Bus::fake([GenerateAudioJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
    ]);

    $shot1 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 1,
      'prompt' => 'Shot 1 prompt',
      'state' => ShotState::JudgingImage,
      'keyframe_path' => 'keyframes/shot_1.png',
    ]);

    $shot2 = Shot::create([
      'brief_id' => $brief->id,
      'sequence' => 2,
      'prompt' => 'Shot 2 prompt',
      'state' => ShotState::JudgingImage,
      'keyframe_path' => 'keyframes/shot_2.png',
    ]);

    $videoProvider = $this->createMock(VideoProviderInterface::class);
    $videoProvider->expects($this->exactly(2))
      ->method('submit')
      ->willReturnOnConsecutiveCalls('render-id-1', 'render-id-2');

    $job = new RenderShotsJob($brief->id);
    $job->handle($videoProvider);

    $shot1->refresh();
    $shot2->refresh();

    $this->assertSame(ShotState::GeneratingVideo, $shot1->state);
    $this->assertSame(ShotState::GeneratingVideo, $shot2->state);
    $this->assertSame('render-id-1', $shot1->metadata['render_id']);
    $this->assertSame('render-id-2', $shot2->metadata['render_id']);

    Bus::assertBatched(function (PendingBatch $batch)
    {
      $this->assertCount(2, $batch->jobs);

      return true;
    });
  }

  public function test_advance_brief_to_audio_transitions_brief_and_dispatches_audio(): void
  {
    Queue::fake([GenerateAudioJob::class]);

    $brief = Brief::create([
      'prompt' => '15s product teaser',
      'state' => BriefState::Rendering,
    ]);

    $callback = new AdvanceBriefToAudioJob($brief->id);
    $batch = $this->createMock(\Illuminate\Bus\Batch::class);

    $callback($batch);

    $this->assertSame(BriefState::Audio, $brief->refresh()->state);
    Queue::assertPushed(GenerateAudioJob::class, fn (GenerateAudioJob $j) => $j->briefId === $brief->id);
  }
}
