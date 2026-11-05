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

        $defaults = $config['defaults'] ?? [];

        $this->assertSame(['default'], $defaults['supervisor-default']['queue'] ?? []);
        $this->assertSame(['generate'], $defaults['supervisor-generate']['queue'] ?? []);
        $this->assertSame(['poll'], $defaults['supervisor-poll']['queue'] ?? []);
        $this->assertSame(['judge'], $defaults['supervisor-judge']['queue'] ?? []);
        $this->assertSame(['audio'], $defaults['supervisor-audio']['queue'] ?? []);
        $this->assertAudioConcurrency($defaults['supervisor-audio']['maxProcesses'] ?? null);

        $environments = $config['environments'] ?? [];
        $this->assertAudioConcurrency($environments['production']['supervisor-audio']['maxProcesses'] ?? null);
        $this->assertAudioConcurrency($environments['local']['supervisor-audio']['maxProcesses'] ?? null);
    }

    private function assertAudioConcurrency(mixed $processes): void
    {
        $this->assertIsInt($processes);
        $this->assertGreaterThanOrEqual(1, $processes);
        $this->assertLessThanOrEqual(2, $processes);
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
