<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\GenerateAudioJob;
use App\Jobs\PollVideoRenderJob;
use App\Jobs\ProcessKeyframesJob;
use App\Jobs\RenderShotsJob;
use Tests\TestCase;

class HorizonQueueConfigTest extends TestCase
{
  public function test_horizon_configures_separate_queues(): void
  {
    $config = require config_path('horizon.php');

    $allQueues = array_merge(
      $config['defaults']['supervisor-1']['queue'] ?? [],
      $config['defaults']['supervisor-audio']['queue'] ?? [],
    );

    $this->assertContains('generate', $allQueues);
    $this->assertContains('poll', $allQueues);
    $this->assertContains('judge', $allQueues);
    $this->assertContains('audio', $allQueues);
  }

  public function test_jobs_are_assigned_to_specified_queues(): void
  {
    $keyframesJob = new ProcessKeyframesJob(1);
    $this->assertSame('generate', $keyframesJob->queue);

    $renderJob = new RenderShotsJob(1);
    $this->assertSame('generate', $renderJob->queue);

    $pollJob = new PollVideoRenderJob(1, 'render-123', 1);
    $this->assertSame('poll', $pollJob->queue);

    $audioJob = new GenerateAudioJob(1);
    $this->assertSame('audio', $audioJob->queue);
  }
}
