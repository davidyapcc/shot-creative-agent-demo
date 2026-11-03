<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentInterface;
use App\Services\Agent\MockAgent;
use Illuminate\Support\ServiceProvider;

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
        default => new MockAgent(),
      };
    });
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
  }
}
