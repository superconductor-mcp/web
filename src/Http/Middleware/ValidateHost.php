<?php

namespace SuperconductorMCP\SSE\Http\Middleware;

use Closure;
use Mariner\Http\Request;
use Mariner\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * DNS rebinding: a page on another site that resolves its own name to this machine reaches the endpoint
 * with that name in Host, and in Origin when it sends one. Both must name an allowed host (2025-06-18 transports).
 */
class ValidateHost
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $allowed = (array) config('sse.allowed_hosts', []);

        if (in_array('*', $allowed, true)) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        $hosts = [self::hostOf((string) $request->headers->get('Host'))];

        if (! is_null($origin)) {
            $hosts[] = self::hostOf((string) parse_url($origin, PHP_URL_HOST));
        }

        foreach ($hosts as $host) {
            if (! in_array($host, $allowed, true)) {
                return new Response("Host [{$host}] is not allowed.", 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
        }

        return $next($request);
    }

    // the host without its port; an IPv6 literal keeps its brackets
    private static function hostOf(string $value): string
    {
        $value = strtolower(trim($value));

        if (str_starts_with($value, '[')) {
            return substr($value, 0, (int) strpos($value, ']') + 1);
        }

        return explode(':', $value, 2)[0];
    }
}
