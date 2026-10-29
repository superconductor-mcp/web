<?php

namespace SuperconductorMCP\SSE\Http\Middleware;

use Closure;
use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Sessions\HttpSessions;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Puts the session the Mcp-Session-Id header names on the request as `mcp.session`. An id this server
 * doesn't know is a 404, so the client starts a new session; GET and DELETE need one, and POST may go
 * without only to initialize, which the messages controller checks.
 */
class ResolveSession
{
    public const string ATTRIBUTE = 'mcp.session';

    public function __construct(private readonly HttpSessions $sessions) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $id = $request->headers->get('Mcp-Session-Id');

        if (is_null($id)) {
            return $request->isMethod('POST')
                ? $next($request)
                : self::refuse(400, 'An Mcp-Session-Id header is required.');
        }

        $session = $this->sessions->find($id, (string) $request->route('server'));

        if (is_null($session)) {
            return self::refuse(404, 'No such session.');
        }

        $request->attributes->set(self::ATTRIBUTE, $session);

        return $next($request);
    }

    public static function refuse(int $status, string $why): Response
    {
        return new Response($why, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
