<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\JsonResponse;
use Mariner\Http\Request;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationServer;

/** GET /.well-known/oauth-authorization-server: the built-in authorization server's metadata (RFC 8414). */
class AuthorizationServerMetadataController
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse($this->server->metadata($request));
    }
}
