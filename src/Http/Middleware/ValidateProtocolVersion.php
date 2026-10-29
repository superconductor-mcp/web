<?php

namespace SuperconductorMCP\SSE\Http\Middleware;

use Closure;
use Mariner\Http\Request;
use Mariner\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// after initialize the client names its protocol version on every request; one this server doesn't speak is a 400
class ValidateProtocolVersion
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $version = $request->headers->get('MCP-Protocol-Version');
        $supported = (array) config('mcp.servers.supported_mcp_versions', []);

        if (! is_null($version) && ! in_array($version, $supported, true)) {
            return new Response("MCP protocol version [{$version}] is not supported.", 400, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }
}
