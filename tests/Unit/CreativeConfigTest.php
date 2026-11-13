<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AudioProviderInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\Services\Providers\ReplayAudioProvider;
use Tests\TestCase;

class CreativeConfigTest extends TestCase
{
    private const DRIVER_ENV_VARS = [
        'AGENT_PROVIDER',
        'AGENT_MODEL',
        'JUDGE_PROVIDER',
        'IMAGE_PROVIDER',
        'IMAGE_MODEL',
        'IMAGE_REQUESTS_PER_MINUTE',
        'VIDEO_PROVIDER',
        'AUDIO_PROVIDER',
        'AUDIO_REQUESTS_PER_MINUTE',
    ];

    protected function tearDown(): void
    {
        foreach (self::DRIVER_ENV_VARS as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }

        parent::tearDown();
    }

    public function test_creative_config_defaults_to_mock_and_replay_drivers(): void
    {
        // The host .env sets AGENT_MODEL; the default is what an unset value gives.
        unset($_SERVER['AGENT_MODEL'], $_ENV['AGENT_MODEL']);
        putenv('AGENT_MODEL');

        $config = $this->loadCreativeConfig();

        $this->assertSame('mock', $config['agent']['provider']);
        $this->assertNull($config['agent']['model']);
        $this->assertSame(10, $config['agent']['requests_per_minute']);
        $this->assertSame('replay', $config['judge']['provider']);
        $this->assertSame('gemini-3.5-flash-lite', $config['judge']['gemini_model']);
        $this->assertSame('gpt-5.4-mini', $config['judge']['openai_model']);
        $this->assertSame(6, $config['judge']['video_fps']);
        $this->assertSame(48, $config['judge']['max_frames']);
        $this->assertSame('replay', $config['image']);
        $this->assertSame('@cf/black-forest-labs/flux-1-schnell', $config['image_model']);
        $this->assertSame(10, $config['image_requests_per_minute']);
        $this->assertSame('replay', $config['video']);
        $this->assertSame('replay', $config['audio']);
        $this->assertSame(10, $config['audio_requests_per_minute']);
    }

    public function test_creative_config_selects_drivers_from_env(): void
    {
        $this->setEnv([
            'AGENT_PROVIDER' => 'gemini',
            'JUDGE_PROVIDER' => 'gemini',
            'IMAGE_PROVIDER' => 'cloudflare',
            'IMAGE_MODEL' => '@cf/black-forest-labs/flux-2-dev',
            'IMAGE_REQUESTS_PER_MINUTE' => '4',
            'VIDEO_PROVIDER' => 'veo',
            'AUDIO_PROVIDER' => 'elevenlabs',
            'AUDIO_REQUESTS_PER_MINUTE' => '2',
        ]);

        $config = $this->loadCreativeConfig();

        $this->assertSame('gemini', $config['agent']['provider']);
        $this->assertSame('gemini', $config['judge']['provider']);
        $this->assertSame('cloudflare', $config['image']);
        $this->assertSame('@cf/black-forest-labs/flux-2-dev', $config['image_model']);
        $this->assertSame(4, $config['image_requests_per_minute']);
        $this->assertSame('veo', $config['video']);
        $this->assertSame('elevenlabs', $config['audio']);
        $this->assertSame(2, $config['audio_requests_per_minute']);
    }

    public function test_container_resolves_image_video_and_judge_providers(): void
    {
        $this->assertInstanceOf(ImageProviderInterface::class, app(ImageProviderInterface::class));
        $this->assertInstanceOf(VideoProviderInterface::class, app(VideoProviderInterface::class));
        $this->assertInstanceOf(JudgeInterface::class, app(JudgeInterface::class));
    }

    public function test_container_resolves_replay_audio_provider(): void
    {
        $audio = app(AudioProviderInterface::class);

        $this->assertInstanceOf(ReplayAudioProvider::class, $audio);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $name => $value) {
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
