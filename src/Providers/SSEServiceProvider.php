<?php

namespace SuperconductorMCP\SSE\Providers;

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientManager;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\Core\FrameworkCore;
use SuperconductorMCP\SSE\Client\SseConnection;
use SuperconductorMCP\SSE\Client\HttpConnection;
use SuperconductorMCP\SSE\Server\McpHttpServer;
use SuperconductorMCP\SSE\Runner\Sketches\HttpServerSketch;
use Voyager\NutsAndBolts\ServiceProvider;

class SSEServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/web.php', 'web');

        // one local HTTP server per application; start() and stop() it from app code or the sketch
        $this->app->registerSingleton(McpHttpServer::class);

        // Rocket only discovers sketches under the app's own paths; a package's sketch joins through sketches.load.
        $this->app['config']->push('sketches.load', HttpServerSketch::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/web.php' => config_path('web.php'),
        ], 'web-config');

        $clients = $this->app->make(ClientManager::class);

        // an mcp_servers entry of type http becomes a client on the 2025-03-26 Streamable HTTP transport
        $clients->extend('http', function (FrameworkCore $app, array $config): MCPClient {
            $loop = $app->make(Loop::class);
            $client = new MCPClient($config['name'], $loop);

            return $client->attach(new HttpConnection($config, $loop, $client));
        });

        // type sse is the 2024-11-05 transport: the server's event stream and POST endpoint. Deprecated with that protocol version.
        $clients->extend('sse', function (FrameworkCore $app, array $config): MCPClient {
            $loop = $app->make(Loop::class);
            $client = new MCPClient($config['name'], $loop);

            return $client->attach(new SseConnection($config, $loop, $client));
        });
    }
}
