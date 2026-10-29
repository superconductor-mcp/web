<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Fabricate\Server\ServerRepository;
use Voyager\NutsAndBolts\ServiceProvider;

// registers the fixture servers by name, as an application's own servers are registered by discovery
class FixtureServersProvider extends ServiceProvider
{
    public function boot(): void
    {
        $servers = $this->app->make(ServerRepository::class);
        $servers->register(FixtureServer::class);
        $servers->register(OtherServer::class);
    }
}
