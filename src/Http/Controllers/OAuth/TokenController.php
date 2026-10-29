<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\JsonResponse;
use Mariner\Http\Request;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationServer;
use SuperconductorMCP\SSE\Auth\Server\OAuthError;

/** POST token: an authorization code or a refresh token exchanged for an access token (RFC 6749 §3.2). */
class TokenController
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->server->token($request), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthError $e) {
            return $e->toResponse();
        }
    }
}
