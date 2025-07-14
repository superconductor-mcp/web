<?php

namespace MCP\Transports\SSE\Http\Controllers;

use MCP\Transports\SSE\Actions\Sagas\TransportProtocol\InitializeSseTransportNode;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpSseEndpoint
{
    public function entry(): StreamedResponse
    {
        $session_id = request()->get('session_id') ?? new_uuid();
        return response()->stream(function() use($session_id): void {
            flow(new InitializeSseTransportNode($session_id), $shared = []);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
