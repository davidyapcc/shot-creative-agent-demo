<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentInterface;
use App\Contracts\ImageProvider;
use App\Contracts\ImageProviderInterface;
use App\Contracts\Judge;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProvider;
use App\Contracts\VideoProviderInterface;
use App\Services\Agent\MockAgent;
use App\Services\Providers\FfmpegMotionVideoProvider;
use App\Services\Providers\ReplayImageProvider;
use App\Services\Providers\ReplayJudge;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   */
  public function register(): void
  {
    $this->app->bind(AgentInterface::class, function ()
    {
      $provider = config('creative.agent.provider', 'mock');

      return match ($provider)
      {
        'mock' => new MockAgent(),
        default => throw new InvalidArgumentException("Unsupported agent provider [{$provider}]."),
      };
    });

    $this->app->bind(ImageProviderInterface::class, function ()
    {
      $provider = config('creative.image', 'replay');

      return match ($provider)
      {
        'replay' => new ReplayImageProvider(),
        default => throw new InvalidArgumentException("Unsupported image provider [{$provider}]."),
      };
    });
    $this->app->alias(ImageProviderInterface::class, ImageProvider::class);

    $this->app->bind(VideoProviderInterface::class, function ()
    {
      $provider = config('creative.video', 'replay');

      $pendingPolls = config('creative.replay_pending_polls') ?? ($this->app->runningUnitTests() ? 0 : 1);

      return match ($provider)
      {
        'replay' => new ReplayVideoProvider(pendingPolls: (int) $pendingPolls),
        'ffmpeg_motion', 'ffmpeg-motion' => new FfmpegMotionVideoProvider(),
        default => throw new InvalidArgumentException("Unsupported video provider [{$provider}]."),
      };
    });
    $this->app->alias(VideoProviderInterface::class, VideoProvider::class);

    $this->app->bind(JudgeInterface::class, function ()
    {
      $provider = config('creative.judge.provider', 'replay');

      return match ($provider)
      {
        'replay' => new ReplayJudge(),
        default => throw new InvalidArgumentException("Unsupported judge provider [{$provider}]."),
      };
    });
    $this->app->alias(JudgeInterface::class, Judge::class);
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
  }
}
