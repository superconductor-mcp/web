<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Fabricate\Server\ServerRepository;
use Mariner\Contracts\Auth\Authenticatable;
use Mariner\Http\Request;
use SuperconductorMCP\SSE\Auth\AccessToken;
use SuperconductorMCP\SSE\Auth\ProtectedResource;

/**
 * The built-in OAuth 2.1 authorization server (MCP 2025-11-25 authorization): the authorization code grant with
 * PKCE S256 only, refresh tokens rotated on every use, tokens bound to one MCP endpoint (RFC 8707), registered
 * clients (RFC 7591) and client metadata documents. Its issuer is the application's scheme and host.
 */
class AuthorizationServer
{
    public function __construct(
        protected readonly Clients $clients,
        protected readonly ServerRepository $servers,
    ) {}

    public static function issuer(Request $request): string
    {
        return strtolower($request->getSchemeAndHttpHost());
    }

    /** The absolute URL of the server's $endpoint (authorize, token, register, revoke, login). */
    public static function endpoint(Request $request, string $endpoint): string
    {
        return self::issuer($request).'/'.trim((string) config('sse.authorization.server.prefix', 'oauth'), '/').'/'.$endpoint;
    }

    /** Where a person who is not logged in is sent: the package's own login page, or the app's (a route name or a path). */
    public static function loginUrl(Request $request): string
    {
        $login = config('sse.authorization.server.login');

        return match (true) {
            is_null($login) => self::endpoint($request, 'login'),
            app('router')->has($login) => route($login),
            default => url($login),
        };
    }

    /** $uri with $params added to its query (null values left out), as an authorization response is sent back. */
    public static function redirect(string $uri, array $params): string
    {
        $query = http_build_query(array_filter($params, fn (mixed $value): bool => ! is_null($value)), '', '&', PHP_QUERY_RFC3986);

        return $uri.(str_contains($uri, '?') ? '&' : '?').$query;
    }

    /**
     * Authorization server metadata (RFC 8414).
     *
     * @return array<string, mixed>
     */
    public function metadata(Request $request): array
    {
        $auth_methods = ['none', 'client_secret_basic', 'client_secret_post'];
        $scopes = array_values((array) config('sse.authorization.scopes', []));

        return array_filter([
            'issuer' => self::issuer($request),
            'authorization_endpoint' => self::endpoint($request, 'authorize'),
            'token_endpoint' => self::endpoint($request, 'token'),
            'registration_endpoint' => config('sse.authorization.server.registration', true) ? self::endpoint($request, 'register') : null,
            'revocation_endpoint' => self::endpoint($request, 'revoke'),
            'scopes_supported' => $scopes === [] ? null : $scopes,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => $auth_methods,
            'revocation_endpoint_auth_methods_supported' => $auth_methods,
            'client_id_metadata_document_supported' => (bool) config('sse.authorization.server.client_metadata_documents', true),
            'authorization_response_iss_parameter_supported' => true,
        ], fn (mixed $value): bool => ! is_null($value));
    }

    /**
     * Check an authorization request's parameters. A bad client or redirect URI is an OAuthError to show the
     * person, never a redirect; anything after that is an OAuthError redirecting to the client (RFC 6749 §4.1.2.1).
     *
     * @param  array<string, mixed>  $params
     *
     * @throws OAuthError
     */
    public function authorizationRequest(array $params, Request $request): AuthorizationRequest
    {
        $client_id = $params['client_id'] ?? null;

        if (! is_string($client_id) || $client_id === '') {
            throw new OAuthError('invalid_request', 'The request names no client.');
        }

        $client = $this->clients->find($client_id);
        $redirect_uri = $params['redirect_uri'] ?? (count($client->redirect_uris) === 1 ? $client->redirect_uris[0] : null);

        if (! is_string($redirect_uri) || ! $client->redirectsTo($redirect_uri)) {
            throw new OAuthError('invalid_request', 'The redirect_uri is not one this client registered.');
        }

        $state = is_string($params['state'] ?? null) ? $params['state'] : null;
        $fail = fn (string $error, string $description): OAuthError => (new OAuthError($error, $description))->redirectingTo($redirect_uri, $state);

        if (($params['response_type'] ?? null) !== 'code') {
            throw $fail('unsupported_response_type', 'Only the authorization code flow (response_type=code) is supported.');
        }

        $challenge = $params['code_challenge'] ?? null;

        // S256 of a verifier is 43 base64url characters; plain is refused (OAuth 2.1 §4.1.1, MCP authorization)
        if (($params['code_challenge_method'] ?? null) !== 'S256' || ! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            throw $fail('invalid_request', 'PKCE is required: a code_challenge with code_challenge_method=S256.');
        }

        $resource = $this->resource($params['resource'] ?? null, $request)
            ?? throw $fail('invalid_target', 'resource must be the URL of one of this application\'s MCP servers.');

        return new AuthorizationRequest($client, $redirect_uri, $state, $challenge, $this->scopes($params['scope'] ?? null), $resource);
    }

    /** A new authorization code for $authorization, approved by $user; the plain code, which is not kept. */
    public function issueCode(AuthorizationRequest $authorization, Authenticatable $user): string
    {
        $code = bin2hex(random_bytes(32));

        AuthorizationCode::query()->create([
            'code' => hash('sha256', $code),
            'grant_id' => bin2hex(random_bytes(16)),
            'client_id' => $authorization->client->id,
            'user_id' => $user->getAuthIdentifier(),
            'redirect_uri' => $authorization->redirect_uri,
            'code_challenge' => $authorization->code_challenge,
            'abilities' => $authorization->scopes,
            'resource' => $authorization->resource,
            'refreshable' => $authorization->client->allows('refresh_token'),
            'expires_at' => now()->addSeconds((int) config('sse.authorization.server.code_ttl', 60)),
        ]);

        return $code;
    }

    /**
     * The token endpoint: the token response for an authorization_code or refresh_token grant.
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function token(Request $request): array
    {
        $client = $this->clients->authenticate($request);

        return match ($request->request->get('grant_type')) {
            'authorization_code' => $this->exchangeCode($request, $client),
            'refresh_token' => $this->refresh($request, $client),
            default => throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };
    }

    /**
     * Token revocation (RFC 7009): a refresh token ends its whole grant, an access token itself. A token that is
     * unknown or another client's is ignored, as the RFC asks.
     *
     * @throws OAuthError invalid_client
     */
    public function revoke(Request $request): void
    {
        $client = $this->clients->authenticate($request);
        $hash = hash('sha256', (string) $request->request->get('token', ''));

        $refresh = RefreshToken::query()->find($hash);

        if (! is_null($refresh)) {
            $refresh->client_id === $client->id && $this->revokeGrant($refresh->grant_id);

            return;
        }

        AccessToken::query()->where('token', $hash)->where('client_id', $client->id)->delete();
    }

    /** @return array<string, mixed> */
    protected function exchangeCode(Request $request, Client $client): array
    {
        $code = AuthorizationCode::query()->find(hash('sha256', (string) $request->request->get('code', '')));

        if (is_null($code) || $code->client_id !== $client->id) {
            throw new OAuthError('invalid_grant', 'The authorization code is not valid.');
        }

        // a code presented twice was intercepted: whatever the first use issued is revoked (OAuth 2.1 §4.1.3)
        if (! is_null($code->used_at)) {
            $this->revokeGrant($code->grant_id);

            throw new OAuthError('invalid_grant', 'The authorization code was already used.');
        }

        if ($code->expires_at->getTimestamp() <= time()) {
            throw new OAuthError('invalid_grant', 'The authorization code has expired.');
        }

        if ($request->request->get('redirect_uri') !== $code->redirect_uri) {
            throw new OAuthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $verifier = (string) $request->request->get('code_verifier', '');

        if (! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || ! hash_equals($code->code_challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
            throw new OAuthError('invalid_grant', 'code_verifier does not match the code_challenge.');
        }

        $this->checkResource($request, $code->resource);

        // only one of two requests racing with the same code gets tokens
        if (AuthorizationCode::query()->where('code', $code->code)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            $this->revokeGrant($code->grant_id);

            throw new OAuthError('invalid_grant', 'The authorization code was already used.');
        }

        return $this->tokens($client, $code->user_id, $code->grant_id, $code->abilities, $code->resource, $code->refreshable);
    }

    /** @return array<string, mixed> */
    protected function refresh(Request $request, Client $client): array
    {
        $refresh = RefreshToken::query()->find(hash('sha256', (string) $request->request->get('refresh_token', '')));

        if (is_null($refresh) || $refresh->client_id !== $client->id) {
            throw new OAuthError('invalid_grant', 'The refresh token is not valid.');
        }

        // a rotated-out refresh token presented again leaked: the grant ends for everyone holding it (OAuth 2.1 §4.3.1)
        if (! is_null($refresh->revoked_at)) {
            $this->revokeGrant($refresh->grant_id);

            throw new OAuthError('invalid_grant', 'The refresh token was already used.');
        }

        if ($refresh->expires_at->getTimestamp() <= time()) {
            throw new OAuthError('invalid_grant', 'The refresh token has expired.');
        }

        $scopes = $refresh->abilities;
        $asked = $request->request->get('scope');

        if (! is_null($asked)) {
            $scopes = array_values(array_filter(explode(' ', (string) $asked)));

            if (array_diff($scopes, $refresh->abilities) !== []) {
                throw new OAuthError('invalid_scope', 'A refresh may only narrow the scopes the grant has.');
            }
        }

        $this->checkResource($request, $refresh->resource);

        if (RefreshToken::query()->where('token', $refresh->token)->whereNull('revoked_at')->update(['revoked_at' => now()]) !== 1) {
            $this->revokeGrant($refresh->grant_id);

            throw new OAuthError('invalid_grant', 'The refresh token was already used.');
        }

        return $this->tokens($client, $refresh->user_id, $refresh->grant_id, $scopes, $refresh->resource, true);
    }

    /**
     * An access token, and a refresh token when the client may refresh: the token response (RFC 6749 §5.1).
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    protected function tokens(Client $client, mixed $user_id, string $grant_id, array $scopes, string $resource, bool $refreshable): array
    {
        $ttl = (int) config('sse.authorization.server.access_ttl', 3600);

        $response = [
            'access_token' => AccessToken::store([
                'user_id' => $user_id,
                'client_id' => $client->id,
                'grant_id' => $grant_id,
                'name' => $client->displayName(),
                'abilities' => $scopes,
                'resource' => $resource,
                'expires_at' => now()->addSeconds($ttl),
            ]),
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];

        if ($scopes !== []) {
            $response['scope'] = implode(' ', $scopes);
        }

        if ($refreshable) {
            $response['refresh_token'] = bin2hex(random_bytes(32));

            RefreshToken::query()->create([
                'token' => hash('sha256', $response['refresh_token']),
                'grant_id' => $grant_id,
                'client_id' => $client->id,
                'user_id' => $user_id,
                'abilities' => $scopes,
                'resource' => $resource,
                'expires_at' => now()->addSeconds((int) config('sse.authorization.server.refresh_ttl', 2592000)),
            ]);
        }

        $this->prune();

        return $response;
    }

    // every token of a grant stops working: its access tokens are deleted and its refresh tokens revoked
    protected function revokeGrant(string $grant_id): void
    {
        AccessToken::query()->where('grant_id', $grant_id)->delete();
        RefreshToken::query()->where('grant_id', $grant_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    // expired codes and tokens are of no use to anyone; the indexes on expires_at keep this cheap
    protected function prune(): void
    {
        $now = now();

        AuthorizationCode::query()->where('expires_at', '<', $now)->delete();
        RefreshToken::query()->where('expires_at', '<', $now)->delete();
        AccessToken::query()->whereNotNull('expires_at')->where('expires_at', '<', $now)->delete();
    }

    // a token request may repeat the resource it was authorized for, never name another
    protected function checkResource(Request $request, string $resource): void
    {
        $asked = $request->request->get('resource');

        if (! is_null($asked) && rtrim((string) $asked, '/') !== $resource) {
            throw new OAuthError('invalid_target', 'resource does not match the grant.');
        }
    }

    // the canonical URI of the MCP server $resource names on this host; when it names none and there is one server, that one
    protected function resource(mixed $resource, Request $request): ?string
    {
        $known = array_map(fn (string $name): string => ProtectedResource::uri($name, self::issuer($request)), array_keys($this->servers->all()));

        if (is_null($resource) || $resource === '') {
            return count($known) === 1 ? $known[0] : null;
        }

        return is_string($resource) && in_array(rtrim($resource, '/'), $known, true) ? rtrim($resource, '/') : null;
    }

    /**
     * The scopes to grant: those asked for that this server knows, or all it knows when none of them are. Unknown
     * scopes are ignored rather than refused (RFC 6749 §3.3), so a client asking for openid or offline_access still works.
     *
     * @return list<string>
     */
    protected function scopes(mixed $scope): array
    {
        $supported = array_values((array) config('sse.authorization.scopes', []));
        $granted = array_values(array_intersect($supported, is_string($scope) ? explode(' ', $scope) : []));

        return $granted === [] ? $supported : $granted;
    }
}
