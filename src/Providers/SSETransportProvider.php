<?php

namespace MCP\Transports\SSE\Providers;

use Illuminate\Support\ServiceProvider;

class SSETransportProvider extends ServiceProvider
{
    protected array $config = [
        'mcp.transports.sse' => __DIR__ .'/../../config/transports/sse.php',
    ];

    public function register(): void
    {
        $this->registerConfigs();
    }

    public function boot(): void
    {
        $this->publishConfigs();
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
    }

    protected function publishConfigs() : void
    {
        $this->publishes([
            $this->config['mcp.transports.sse'] => config_path('mcp/transports/sse.php'),
        ], ['mcp.transports', 'mcp.transports.sse']);
    }

    protected function registerConfigs() : void
    {
        foreach ($this->config as $key => $path) {
            $this->mergeConfigFrom($path, $key);
        }
    }
}
