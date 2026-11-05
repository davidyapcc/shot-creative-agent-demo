<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentInterface;
use App\Contracts\AudioProviderInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\Jobs\GenerateAudioJob;
use App\Services\Agent\MockAgent;
use App\Services\Providers\FfmpegMotionVideoProvider;
use App\Services\Providers\ReplayAudioProvider;
use App\Services\Providers\ReplayImageProvider;
use App\Services\Providers\ReplayJudge;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgentInterface::class, function () {
            $provider = config('creative.agent.provider', 'mock');

            return match ($provider) {
                'mock' => new MockAgent,
                default => throw new InvalidArgumentException("Unsupported agent provider [{$provider}]."),
            };
        });

        $this->app->bind(ImageProviderInterface::class, function () {
            $provider = config('creative.image', 'replay');

            return match ($provider) {
                'replay' => new ReplayImageProvider,
                default => throw new InvalidArgumentException("Unsupported image provider [{$provider}]."),
            };
        });

        $this->app->bind(VideoProviderInterface::class, function () {
            $provider = config('creative.video', 'replay');

            return match ($provider) {
                'replay' => new ReplayVideoProvider(
                    pendingPolls: (int) config('creative.replay_pending_polls', 1),
                ),
                'ffmpeg_motion' => new FfmpegMotionVideoProvider,
                default => throw new InvalidArgumentException("Unsupported video provider [{$provider}]."),
            };
        });

        $this->app->bind(AudioProviderInterface::class, function () {
            $provider = config('creative.audio', 'replay');

            return match ($provider) {
                'replay' => new ReplayAudioProvider,
                default => throw new InvalidArgumentException("Unsupported audio provider [{$provider}]."),
            };
        });

        $this->app->bind(JudgeInterface::class, function () {
            $provider = config('creative.judge.provider', 'replay');

            return match ($provider) {
                'replay' => new ReplayJudge,
                default => throw new InvalidArgumentException("Unsupported judge provider [{$provider}]."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(GenerateAudioJob::RATE_LIMITER, function (): Limit {
            return Limit::perMinute((int) config('creative.audio_requests_per_minute', 10));
        });
    }
}
