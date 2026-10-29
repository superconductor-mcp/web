<?php

namespace SuperconductorMCP\SSE\Http\Middleware;

use Closure;
use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Auth\BearerToken;
use SuperconductorMCP\SSE\Auth\ProtectedResource;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Authorization (2025-03-26 onward; 2025-11-25 scope step-up): with `sse.authorization.guard` set, every request
 * needs a bearer token that guard accepts, or it is a 401 whose WWW-Authenticate names the server's protected
 * resource metadata. A token without every scope `sse.authorization.scopes` lists is a 403 insufficient_scope.
 * The user goes on the request as `mcp.user`. With no guard the endpoint is open.
 */
class Authenticate
{
    public const string USER = 'mcp.user';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $guard = config('sse.authorization.guard');

        if (is_null($guard)) {
            return $next($request);
        }

        $user = app('auth')->guard($guard)->user();
        $required = array_values((array) config('sse.authorization.scopes', []));

        if (is_null($user)) {
            return self::challenge($request, 401, $required, $request->bearerToken() === null ? null : 'invalid_token');
        }

        $token = $request->attributes->get(BearerToken::ATTRIBUTE);
        $granted = $token instanceof BearerToken ? $token->scopes() : [];

        if (array_diff($required, $granted) !== []) {
            return self::challenge($request, 403, $required, 'insufficient_scope');
        }

        $request->attributes->set(self::USER, $user);

        return $next($request);
    }

    /** @param list<string> $scopes */
    private static function challenge(Request $request, int $status, array $scopes, ?string $error): Response
    {
        $params = is_null($error) ? [] : ["error=\"{$error}\""];

        if ($scopes !== []) {
            $params[] = 'scope="'.implode(' ', $scopes).'"';
        }

        $params[] = 'resource_metadata="'.ProtectedResource::metadataUrl((string) $request->route('server'), $request->getSchemeAndHttpHost()).'"';

        return new Response($status === 401 ? 'A bearer token is required.' : 'The token lacks a required scope.', $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'WWW-Authenticate' => 'Bearer '.implode(', ', $params),
        ]);
    }
}
