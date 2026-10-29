<?php

return [
    Mariner\Core\Providers\MarinerServiceProvider::class,
    Fabricate\Core\Providers\SuperconductorMCPServiceProvider::class,
    SuperconductorMCP\SSE\Providers\SSEServiceProvider::class,
    SuperconductorMCP\SSE\Tests\Fixtures\FixtureServersProvider::class,
];
