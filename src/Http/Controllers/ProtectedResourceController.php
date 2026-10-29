<?php

namespace SuperconductorMCP\SSE\Http\Controllers;

use Fabricate\Server\ServerRepository;
use Mariner\Http\JsonResponse;
use Mariner\Http\Request;
use SuperconductorMCP\SSE\Auth\ProtectedResource;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationServer;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /.well-known/oauth-protected-resource/<endpoint path>: the server's protected resource metadata
 * (RFC 9728), telling a client which authorization servers issue its tokens and which scopes it knows.
 */
class ProtectedResourceController
{
    public function __construct(private readonly ServerRepository $servers) {}

    public function __invoke(Request $request, string $server): SymfonyResponse
    {
        if (! $this->servers->has($server)) {
            return ResolveSession::refuse(404, "No MCP server [{$server}].");
        }

        $metadata = [
            'resource' => ProtectedResource::uri($server, $request->getSchemeAndHttpHost()),
            'authorization_servers' => array_values(array_unique([
                ...(config('sse.authorization.server.enabled') ? [AuthorizationServer::issuer($request)] : []),
                ...(array) config('sse.authorization.servers', []),
            ])),
            'bearer_methods_supported' => ['header'],
        ];

        if (($scopes = (array) config('sse.authorization.scopes', [])) !== []) {
            $metadata['scopes_supported'] = array_values($scopes);
        }

        return new JsonResponse($metadata);
    }
}
