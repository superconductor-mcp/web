<?php

namespace SuperconductorMCP\SSE\Http\Controllers;

use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Sessions\HttpSessions;

// DELETE: the client ends its session; its open streams end on their next look
class SessionController
{
    public function __construct(private readonly HttpSessions $sessions) {}

    public function __invoke(Request $request): Response
    {
        $this->sessions->forget($request->attributes->get(ResolveSession::ATTRIBUTE));

        return new Response('', 204);
    }
}
