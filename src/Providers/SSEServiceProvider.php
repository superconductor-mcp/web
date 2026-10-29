<?php

namespace SuperconductorMCP\SSE\Providers;

use Mariner\Auth\RequestGuard;
use Mariner\NutsAndBolts\Concerns\LoadsWebResources;
use RuntimeException;
use SuperconductorMCP\SSE\Auth\AccessToken;
use SuperconductorMCP\SSE\Auth\Server\Clients;
use SuperconductorMCP\SSE\Http\Middleware\Authenticate;
use SuperconductorMCP\SSE\Sessions\HttpSessions;
use Voyager\NutsAndBolts\ServiceProvider;

class SSEServiceProvider extends ServiceProvider
{
    use LoadsWebResources;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sse.php', 'sse');

        $this->app->registerSingleton(HttpSessions::class);

        // metadata documents are fetched through the app's HTTP client, so its configuration and fakes apply
        $this->app->registerSingleton(Clients::class, fn ($app) => new Clients($app['http']));

        // the built-in authorization server's tokens are mcp_access_tokens, so the endpoint checks them unless told otherwise
        if (config('sse.authorization.server.enabled') && is_null(config('sse.authorization.guard'))) {
            config(['sse.authorization.guard' => 'mcp']);
        }

        // the configured controllers and middleware are the container's, so an app swaps one through config alone
        foreach ([...config('sse.controllers', []), ...config('sse.middleware', [])] as $class) {
            $this->app->registerSingleton($class);
        }
    }

    public function boot(): void
    {
        // the 'mcp' guard reads mcp_access_tokens, unless the app defines its own guard by that name. Set at boot:
        // set earlier, an auth config would exist before serve's provider merges its own, which would then add nothing
        if (is_null(config('auth.guards.mcp'))) {
            config(['auth.guards.mcp' => ['driver' => 'mcp-token', 'provider' => 'users']]);
        }

        $router = $this->app->make('router');

        // a guard with no Authenticate in the endpoint's middleware (a config/sse.php published before it existed) would leave the endpoint open
        if (! is_null(config('sse.authorization.guard')) && array_filter(config('sse.middleware', []), fn (string $class) => is_a($class, Authenticate::class, true)) === []) {
            throw new RuntimeException('sse.authorization.guard is set, but sse.middleware has no '.Authenticate::class.' entry: add \'auth\' => '.Authenticate::class.'::class after \'host\'.');
        }

        $guard = config('sse.authorization.guard');

        // a token guard that does not read mcp_access_tokens would refuse every token the built-in server issues
        if (config('sse.authorization.server.enabled') && config("auth.guards.{$guard}.driver") !== 'mcp-token') {
            throw new RuntimeException("sse.authorization.server is enabled, but sse.authorization.guard [{$guard}] does not use the mcp-token driver, so it would refuse the tokens the server issues.");
        }

        if (! is_null($guard)) {
            // browser-based clients read the metadata and call the token endpoints across origins
            $prefix = trim((string) config('sse.authorization.server.prefix', 'oauth'), '/');
            config(['cors.paths' => array_values(array_unique([...(array) config('cors.paths', []), '.well-known/oauth-*', "{$prefix}/token", "{$prefix}/register", "{$prefix}/revoke"]))]);
        }

        foreach (config('sse.middleware', []) as $key => $class) {
            $router->aliasMiddleware("mcp.{$key}", $class);
        }

        $this->app->make('auth')->extend('mcp-token', function ($app, string $name, array $config) {
            return new RequestGuard(AccessToken::authenticate(...), $app['request'], $this->createUserProvider($config['provider'] ?? null));
        });

        $this->loadRoutesFrom(config('sse.routes') ?? __DIR__.'/../../routes/web.php');

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'sse');

        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/sse'),
        ], 'sse-views');

        $this->publishes([
            __DIR__.'/../../config/sse.php' => config_path('sse.php'),
        ], 'sse-config');

        // the package's migrations are undated and never run from here: the app publishes them, with its own prefix
        $this->publishes(self::migrations(), 'sse-migrations');
    }

    /**
     * Each undated migration => where the app keeps it: its existing copy (whatever its prefix), else a new one
     * dated now, a second apart in this order so tokens come before the tables that point at them. A second
     * publish finds the first copy, so it never writes a duplicate.
     *
     * @return array<string, string>
     */
    public static function migrations(): array
    {
        $published = [];
        $at = time();

        foreach (['create_mcp_access_tokens_table', 'create_mcp_oauth_tables'] as $i => $name) {
            $existing = glob(database_path("migrations/*_{$name}.php")) ?: [];
            $published[__DIR__."/../../database/migrations/{$name}.php"] = $existing[0] ?? database_path('migrations/'.date('Y_m_d_His', $at + $i)."_{$name}.php");
        }

        return $published;
    }
}
