<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentInterface;
use App\Contracts\AudioProviderInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\Events\BriefStateChanged;
use App\Jobs\GenerateAudioJob;
use App\Jobs\JudgeKeyframeJob;
use App\Jobs\PlanBriefJob;
use App\Listeners\BroadcastFinishedBriefTelemetry;
use App\Services\Agent\MockAgent;
use App\Services\Agent\PrismAgent;
use App\Services\JudgeFactory;
use App\Services\Providers\FfmpegMotionVideoProvider;
use App\Services\Providers\ReplayAudioProvider;
use App\Services\Providers\ReplayImageProvider;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
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
                'gemini' => $this->prismAgent('gemini', 'services.gemini.key', 'GEMINI_API_KEY'),
                'openai' => $this->prismAgent('openai', 'services.openai.key', 'OPENAI_API_KEY'),
                'cloudflare' => $this->cloudflareAgent(),
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

            return $this->app->make(JudgeFactory::class)->make(is_string($provider) ? $provider : 'replay');
        });
    }

    private function prismAgent(string $provider, string $configKey, string $envName, ?string $url = null): PrismAgent
    {
        return new PrismAgent(
            provider: $provider,
            model: $this->agentModel($provider),
            apiKey: $this->agentKey($configKey, $envName, $provider),
            url: $url,
        );
    }

    private function cloudflareAgent(): PrismAgent
    {
        $accountId = config('services.cloudflare.account_id');

        if (! is_string($accountId) || trim($accountId) === '') {
            throw new InvalidArgumentException('CLOUDFLARE_ACCOUNT_ID is required when AGENT_PROVIDER=cloudflare.');
        }

        return $this->prismAgent(
            'cloudflare',
            'services.cloudflare.key',
            'CLOUDFLARE_API_TOKEN',
            'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode(trim($accountId)).'/ai/v1',
        );
    }

    private function agentModel(string $provider): string
    {
        $model = config('creative.agent.model');

        if (! is_string($model) || trim($model) === '') {
            $model = config("creative.agent.default_models.{$provider}");
        }

        if (! is_string($model) || trim($model) === '') {
            throw new InvalidArgumentException("AGENT_MODEL is required when AGENT_PROVIDER={$provider}.");
        }

        return trim($model);
    }

    private function agentKey(string $configKey, string $envName, string $provider): string
    {
        $key = config($configKey);

        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException("{$envName} is required when AGENT_PROVIDER={$provider}.");
        }

        return trim($key);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(BriefStateChanged::class, BroadcastFinishedBriefTelemetry::class);

        RateLimiter::for(PlanBriefJob::RATE_LIMITER, function (): Limit {
            return Limit::perMinute((int) config('creative.agent.requests_per_minute'));
        });

        RateLimiter::for(GenerateAudioJob::RATE_LIMITER, function (): Limit {
            return Limit::perMinute((int) config('creative.audio_requests_per_minute'));
        });

        RateLimiter::for(JudgeKeyframeJob::RATE_LIMITER, function (): Limit {
            return Limit::perMinute((int) config('creative.judge.requests_per_minute'));
        });
    }
}
