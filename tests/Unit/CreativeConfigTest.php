<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class CreativeConfigTest extends TestCase
{
  public function test_creative_config_defaults_to_mock_and_replay_drivers(): void
  {
    $config = config('creative');

    $this->assertSame('mock', $config['agent']['provider']);
    $this->assertSame('gemini-3.8-flash', $config['agent']['model']);
    $this->assertSame('replay', $config['judge']['provider']);
    $this->assertSame('gemini-3.5-flash-lite', $config['judge']['model']);
    $this->assertSame('replay', $config['image']);
    $this->assertSame('replay', $config['video']);
    $this->assertSame('replay', $config['audio']);
  }

  public function test_creative_config_reads_custom_env_drivers(): void
  {
    config([
      'creative.agent.provider' => 'gemini',
      'creative.judge.provider' => 'gemini',
      'creative.image' => 'flux',
      'creative.video' => 'veo',
      'creative.audio' => 'elevenlabs',
    ]);

    $this->assertSame('gemini', config('creative.agent.provider'));
    $this->assertSame('gemini', config('creative.judge.provider'));
    $this->assertSame('flux', config('creative.image'));
    $this->assertSame('veo', config('creative.video'));
    $this->assertSame('elevenlabs', config('creative.audio'));
  }
}
