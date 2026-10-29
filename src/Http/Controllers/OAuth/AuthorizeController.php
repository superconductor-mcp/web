<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationRequest;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationServer;
use SuperconductorMCP\SSE\Auth\Server\Clients;
use SuperconductorMCP\SSE\Auth\Server\OAuthError;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The authorization endpoint. GET checks the request, sends a person who is not logged in to the login page and
 * back, and asks them to approve the client; POST is their answer, which sends the client a code or access_denied.
 * The request waits in the session between the two, so the answer cannot be pointed at other parameters.
 */
class AuthorizeController
{
    // the consent page must not be framed by another site (clickjacking)
    private const array HEADERS = ['X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "frame-ancestors 'none'", 'Cache-Control' => 'no-store'];

    // authorization requests a session holds for an answer at once
    private const int PENDING = 10;

    public function __construct(private readonly AuthorizationServer $server) {}

    public function show(Request $request): SymfonyResponse
    {
        try {
            $authorization = $this->server->authorizationRequest($request->query->all(), $request);
        } catch (OAuthError $e) {
            return $this->fail($request, $e);
        }

        $guard = auth()->guard(config('sse.authorization.server.session_guard', 'web'));

        if ($guard->guest()) {
            return redirect()->guest(AuthorizationServer::loginUrl($request));
        }

        // the newest few wait for an answer, so a page that opens this one over and over cannot fill the session
        $id = bin2hex(random_bytes(16));
        $pending = array_slice((array) $request->session()->get('mcp_oauth', []), -(self::PENDING - 1), null, true);
        $request->session()->put('mcp_oauth', [...$pending, $id => ['params' => $request->query->all(), 'user' => $guard->id()]]);

        return new Response(view('sse::oauth.authorize', [
            'request_id' => $id,
            'client' => $authorization->client->displayName(),
            'client_host' => Clients::isMetadataDocument($authorization->client->id) ? parse_url($authorization->client->id, PHP_URL_HOST) : null,
            'redirect_host' => parse_url($authorization->redirect_uri, PHP_URL_HOST),
            'resource' => $authorization->resource,
            'scopes' => $authorization->scopes,
            'user' => $guard->user(),
            'action' => AuthorizationServer::endpoint($request, 'authorize'),
        ])->render(), 200, self::HEADERS);
    }

    public function decide(Request $request): SymfonyResponse
    {
        $all = (array) $request->session()->get('mcp_oauth', []);
        $pending = $all[(string) $request->input('request')] ?? null;
        unset($all[(string) $request->input('request')]);
        $request->session()->put('mcp_oauth', $all);
        $guard = auth()->guard(config('sse.authorization.server.session_guard', 'web'));

        if (! is_array($pending) || $guard->guest() || $pending['user'] !== $guard->id()) {
            return $this->page('invalid_request', 'This authorization request has expired or was already answered. Start again from the application.');
        }

        try {
            $authorization = $this->server->authorizationRequest($pending['params'], $request);
        } catch (OAuthError $e) {
            return $this->fail($request, $e);
        }

        if ($request->input('decision') !== 'approve') {
            return $this->answer($request, $authorization, ['error' => 'access_denied', 'error_description' => 'The person declined.']);
        }

        return $this->answer($request, $authorization, ['code' => $this->server->issueCode($authorization, $guard->user())]);
    }

    // RFC 9207: the response names its issuer, so a client talking to several servers can tell them apart
    private function answer(Request $request, AuthorizationRequest $authorization, array $params): SymfonyResponse
    {
        return redirect()->away(AuthorizationServer::redirect($authorization->redirect_uri, [...$params, 'state' => $authorization->state, 'iss' => AuthorizationServer::issuer($request)]));
    }

    private function fail(Request $request, OAuthError $e): SymfonyResponse
    {
        if (is_null($e->redirect_uri)) {
            return $this->page($e->error, $e->getMessage());
        }

        return redirect()->away(AuthorizationServer::redirect($e->redirect_uri, [
            'error' => $e->error,
            'error_description' => $e->getMessage(),
            'state' => $e->state,
            'iss' => AuthorizationServer::issuer($request),
        ]));
    }

    // an error the client cannot be trusted to receive is shown to the person instead
    private function page(string $error, string $description): Response
    {
        return new Response(view('sse::oauth.error', ['error' => $error, 'description' => $description])->render(), 400, self::HEADERS);
    }
}
