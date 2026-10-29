<?php

// a test may register them in another order, as package discovery might
return SuperconductorMCP\SSE\Tests\Fixtures\McpApp::$providers ?: [
    Mariner\Core\Providers\MarinerServiceProvider::class,
    Fabricate\Core\Providers\SuperconductorMCPServiceProvider::class,
    SuperconductorMCP\SSE\Providers\SSEServiceProvider::class,
    SuperconductorMCP\SSE\Tests\Fixtures\FixtureServersProvider::class,
];
