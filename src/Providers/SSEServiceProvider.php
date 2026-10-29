<?php

namespace SuperconductorMCP\SSE\Providers;

use Mariner\NutsAndBolts\Concerns\LoadsWebResources;
use SuperconductorMCP\SSE\Sessions\HttpSessions;
use Voyager\NutsAndBolts\ServiceProvider;

class SSEServiceProvider extends ServiceProvider
{
    use LoadsWebResources;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sse.php', 'sse');

        $this->app->registerSingleton(HttpSessions::class);

        // the configured controllers and middleware are the container's, so an app swaps one through config alone
        foreach ([...config('sse.controllers', []), ...config('sse.middleware', [])] as $class) {
            $this->app->registerSingleton($class);
        }
    }

    public function boot(): void
    {
        $router = $this->app->make('router');

        foreach (config('sse.middleware', []) as $key => $class) {
            $router->aliasMiddleware("mcp.{$key}", $class);
        }

        $this->loadRoutesFrom(config('sse.routes') ?? __DIR__.'/../../routes/web.php');

        $this->publishes([
            __DIR__.'/../../config/sse.php' => config_path('sse.php'),
        ], 'sse-config');
    }
}
