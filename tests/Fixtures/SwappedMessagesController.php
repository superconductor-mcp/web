<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Mariner\Http\Response;

// an application's own controller behind POST /mcp/{server}, named in config/sse.php
class SwappedMessagesController
{
    public function __invoke(string $server): Response
    {
        return new Response("swapped for {$server}", 200, ['Content-Type' => 'text/plain']);
    }
}
