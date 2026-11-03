<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use Tests\TestCase;

class CreativeConfigTest extends TestCase
{
  private const DRIVER_ENV_VARS = [
    'AGENT_PROVIDER',
    'JUDGE_PROVIDER',
    'IMAGE_PROVIDER',
    'VIDEO_PROVIDER',
    'AUDIO_PROVIDER',
  ];

  protected function tearDown(): void
  {
    foreach (self::DRIVER_ENV_VARS as $name)
    {
      unset($_SERVER[$name], $_ENV[$name]);
    }

    parent::tearDown();
  }

  public function test_creative_config_defaults_to_mock_and_replay_drivers(): void
  {
    $config = $this->loadCreativeConfig();

    $this->assertSame('mock', $config['agent']['provider']);
    $this->assertSame('replay', $config['judge']['provider']);
    $this->assertSame('replay', $config['image']);
    $this->assertSame('replay', $config['video']);
    $this->assertSame('replay', $config['audio']);
  }

  public function test_creative_config_selects_drivers_from_env(): void
  {
    $this->setEnv([
      'AGENT_PROVIDER' => 'gemini',
      'JUDGE_PROVIDER' => 'gemini',
      'IMAGE_PROVIDER' => 'flux',
      'VIDEO_PROVIDER' => 'veo',
      'AUDIO_PROVIDER' => 'elevenlabs',
    ]);

    $config = $this->loadCreativeConfig();

    $this->assertSame('gemini', $config['agent']['provider']);
    $this->assertSame('gemini', $config['judge']['provider']);
    $this->assertSame('flux', $config['image']);
    $this->assertSame('veo', $config['video']);
    $this->assertSame('elevenlabs', $config['audio']);
  }

  public function test_container_resolves_image_video_and_judge_providers(): void
  {
    $this->assertInstanceOf(ImageProviderInterface::class, app(ImageProviderInterface::class));
    $this->assertInstanceOf(VideoProviderInterface::class, app(VideoProviderInterface::class));
    $this->assertInstanceOf(JudgeInterface::class, app(JudgeInterface::class));
  }

  /**
   * @param array<string, string> $values
   */
  private function setEnv(array $values): void
  {
    foreach ($values as $name => $value)
    {
      $_SERVER[$name] = $value;
      $_ENV[$name] = $value;
    }
  }

  /**
   * Evaluate the config file fresh so env() is read now, not at boot.
   *
   * @return array<string, mixed>
   */
  private function loadCreativeConfig(): array
  {
    return require config_path('creative.php');
  }
}
