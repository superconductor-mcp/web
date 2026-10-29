<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationServer;
use SuperconductorMCP\SSE\Auth\Server\OAuthError;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** POST revoke: token revocation (RFC 7009); 200 whether or not the token was known. */
class RevokeController
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        try {
            $this->server->revoke($request);
        } catch (OAuthError $e) {
            return $e->toResponse();
        }

        return new Response('', 200, ['Cache-Control' => 'no-store']);
    }
}
