<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\JsonResponse;
use Mariner\Http\Request;
use SuperconductorMCP\SSE\Auth\Server\Clients;
use SuperconductorMCP\SSE\Auth\Server\OAuthError;

/** POST register: dynamic client registration (RFC 7591); 201 with the client's id, and its secret when it is confidential. */
class RegisterController
{
    public function __construct(private readonly Clients $clients) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->clients->register(json_decode($request->getContent(), true)), 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthError $e) {
            return $e->toResponse();
        }
    }
}
