<?php

use Mariner\Auth\User;
use SuperconductorMCP\SSE\Auth\AccessToken;
use SuperconductorMCP\SSE\Auth\Server\AuthorizationCode;
use SuperconductorMCP\SSE\Auth\Server\Clients;
use SuperconductorMCP\SSE\Auth\Server\RefreshToken;
use SuperconductorMCP\SSE\Tests\Fixtures\OAuthCase;
use Voyager\Http\Client\Factory as Http;

// The built-in authorization server (MCP 2025-11-25 authorization): sign in, approve, a code, tokens bound to one
// endpoint, rotation, revocation, registration and client metadata documents.

const RESOURCE = 'http://localhost/mcp/fixture';
const CALLBACK = 'http://127.0.0.1:33418/callback';

function oauthReboot(OAuthCase $test, array $server = [], array $authorization = []): void
{
    $sse = OAuthCase::defaults($server);
    $sse['authorization'] = [...$sse['authorization'], ...$authorization];
    $test->rebootWith($sse);
    config(['session.driver' => 'array', 'cache.default' => 'array']);
    mcpDatabase($test);
}

function person(string $email = 'ada@example.com', string $password = 'correct horse'): User
{
    app('db')->table('users')->insert(['name' => strtok($email, '@'), 'email' => $email, 'password' => app('hash')->make($password)]);

    return User::query()->where('email', $email)->first();
}

/** A public client registered through the registration endpoint; its client_id. */
function registered(OAuthCase $test, array $metadata = []): string
{
    $response = $test->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['client_name' => 'Inspector', 'redirect_uris' => [CALLBACK], 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none', ...$metadata]));
    $response->assertStatus(201);

    return $response->json('client_id');
}

/** @return array{0: string, 1: string} a PKCE verifier and its S256 challenge */
function pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

function authorizeUrl(string $client_id, string $challenge, array $overrides = []): string
{
    return '/oauth/authorize?'.http_build_query(array_filter([
        'response_type' => 'code', 'client_id' => $client_id, 'redirect_uri' => CALLBACK, 'code_challenge' => $challenge,
        'code_challenge_method' => 'S256', 'state' => 'xyz', 'resource' => RESOURCE, 'scope' => 'mcp:tools mcp:resources', ...$overrides,
    ], fn ($value) => ! is_null($value)));
}

/** Sign in as $user (already approved by the browser's jar), approve, and return the redirect's query. */
function approve(OAuthCase $test, string $url, string $decision = 'approve'): array
{
    $page = $test->browse('GET', $url);
    $page->assertStatus(200);
    preg_match('/name="request" value="([0-9a-f]{32})"/', $page->getContent(), $match);
    $answer = $test->browse('POST', '/oauth/authorize', ['request' => $match[1] ?? '', 'decision' => $decision]);
    $answer->assertStatus(302);
    parse_str((string) parse_url($answer->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

function signIn(OAuthCase $test, string $email = 'ada@example.com', string $password = 'correct horse'): void
{
    $test->browse('GET', '/oauth/login')->assertStatus(200);
    $test->browse('POST', '/oauth/login', ['email' => $email, 'password' => $password])->assertStatus(302);
}

function exchange(OAuthCase $test, array $params): Mariner\Testing\TestResponse
{
    return $test->call('POST', '/oauth/token', $params);
}

/** The whole flow for a fresh public client: its client_id and the token response. */
function tokens(OAuthCase $test): array
{
    person();
    $client = registered($test);
    [$verifier, $challenge] = pkce();
    signIn($test);
    $query = approve($test, authorizeUrl($client, $challenge));

    $response = exchange($test, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier, 'resource' => RESOURCE]);
    $response->assertStatus(200);

    return [$client, $response->json()];
}

/** The MCP endpoint's answer to $token: a DELETE on a session that does not exist is 404 once the token passes. */
function endpointStatus(OAuthCase $test, ?string $token, string $server = 'fixture'): int
{
    // each request served has its own guards; requests made one after another in a test share them unless forgotten
    app('auth')->forgetGuards();

    return $test->call('DELETE', "/mcp/{$server}", [], [], [], array_filter(['HTTP_MCP_SESSION_ID' => 'nope', 'HTTP_AUTHORIZATION' => is_null($token) ? null : "Bearer {$token}"]))->getStatusCode();
}

it('publishes its metadata, and the endpoint names it as its authorization server', function () {
    $metadata = $this->get('/.well-known/oauth-authorization-server')->assertStatus(200)->json();
    $resource = $this->get('/.well-known/oauth-protected-resource/mcp/fixture')->assertStatus(200)->json();

    expect($metadata)->toBe([
        'issuer' => 'http://localhost',
        'authorization_endpoint' => 'http://localhost/oauth/authorize',
        'token_endpoint' => 'http://localhost/oauth/token',
        'registration_endpoint' => 'http://localhost/oauth/register',
        'revocation_endpoint' => 'http://localhost/oauth/revoke',
        'scopes_supported' => ['mcp:tools', 'mcp:resources'],
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        'client_id_metadata_document_supported' => true,
        'authorization_response_iss_parameter_supported' => true,
    ])
        ->and($resource['authorization_servers'])->toBe(['http://localhost'])
        ->and(endpointStatus($this, null))->toBe(401);
});

it('sends a person who is not signed in to log in, then back to the request, and gives the client tokens for one endpoint', function () {
    person();
    $client = registered($this);
    [$verifier, $challenge] = pkce();
    $url = authorizeUrl($client, $challenge);

    $this->browse('GET', $url)->assertRedirect('http://localhost/oauth/login');
    $this->browse('GET', '/oauth/login')->assertStatus(200)->assertSee('Sign in')->assertHeader('X-Frame-Options', 'DENY');
    $back = $this->browse('POST', '/oauth/login', ['email' => 'ada@example.com', 'password' => 'correct horse']);
    parse_str((string) parse_url($back->headers->get('Location'), PHP_URL_QUERY), $returned);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $asked);
    expect($back->getStatusCode())->toBe(302)->and($back->headers->get('Location'))->toStartWith('http://localhost/oauth/authorize?')->and($returned)->toEqualCanonicalizing($asked);

    $consent = $this->browse('GET', $url);
    $consent->assertStatus(200)->assertSee('Allow Inspector to use this MCP server?')->assertSee('127.0.0.1')->assertSee(RESOURCE)
        ->assertSee('its name is not verified')->assertHeader('X-Frame-Options', 'DENY');

    $query = approve($this, $url);
    $response = exchange($this, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier, 'resource' => RESOURCE]);
    $token = AccessToken::query()->first();

    expect($query['state'])->toBe('xyz')
        ->and($query['iss'])->toBe('http://localhost')
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json())->toHaveKeys(['access_token', 'refresh_token'])
        ->and($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('expires_in'))->toBe(3600)
        ->and($response->json('scope'))->toBe('mcp:tools mcp:resources')
        ->and([$token->client_id, $token->resource, $token->abilities, $token->name])->toBe([$client, RESOURCE, ['mcp:tools', 'mcp:resources'], 'Inspector'])
        ->and(endpointStatus($this, $response->json('access_token')))->toBe(404)
        ->and(endpointStatus($this, $response->json('access_token'), 'other'))->toBe(401);
});

it('refuses a login or an approval without the form\'s CSRF token', function () {
    person();
    $client = registered($this);

    $this->browse('GET', '/oauth/login');
    $login = $this->browse('POST', '/oauth/login', ['email' => 'ada@example.com', 'password' => 'correct horse', '_token' => 'forged']);
    signIn($this);
    preg_match('/name="request" value="([0-9a-f]{32})"/', $this->browse('GET', authorizeUrl($client, pkce()[1]))->getContent(), $match);
    $approval = $this->browse('POST', '/oauth/authorize', ['request' => $match[1], 'decision' => 'approve', '_token' => 'forged']);

    expect($login->getStatusCode())->toBe(419)->and($approval->getStatusCode())->toBe(419)
        ->and(AuthorizationCode::query()->count())->toBe(0);
});

it('refuses a wrong password, and stops trying after five', function () {
    person();

    $this->browse('GET', '/oauth/login');
    $this->browse('POST', '/oauth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertRedirect('http://localhost/oauth/login');
    $this->browse('GET', '/oauth/login')->assertSee('These credentials do not match our records.')->assertSee('ada@example.com');

    foreach (range(2, 5) as $attempt) {
        $this->browse('POST', '/oauth/login', ['email' => 'ada@example.com', 'password' => 'wrong']);
    }

    $this->browse('POST', '/oauth/login', ['email' => 'ada@example.com', 'password' => 'correct horse'])->assertStatus(429);
});

it('sends access_denied when the person declines', function () {
    person();
    $client = registered($this);
    signIn($this);

    $query = approve($this, authorizeUrl($client, pkce()[1]), 'deny');

    expect($query)->toMatchArray(['error' => 'access_denied', 'state' => 'xyz', 'iss' => 'http://localhost'])->not->toHaveKey('code');
});

it('shows the person, and never redirects, an unknown client or a redirect URI it did not register', function () {
    person();
    $client = registered($this);
    signIn($this);
    [, $challenge] = pkce();

    $unknown = $this->browse('GET', authorizeUrl('mcp_nobody', $challenge));
    $elsewhere = $this->browse('GET', authorizeUrl($client, $challenge, ['redirect_uri' => 'https://evil.example/cb']));

    expect($unknown->getStatusCode())->toBe(400)->and($unknown->getContent())->toContain('Unknown client.')
        ->and($elsewhere->getStatusCode())->toBe(400)->and($elsewhere->getContent())->toContain('not one this client registered')
        ->and($elsewhere->headers->has('Location'))->toBeFalse();
});

it('redirects the client the error of a request without S256 PKCE, another response type, or a resource it does not serve', function (array $overrides, string $error) {
    person();
    $client = registered($this);
    signIn($this);

    $response = $this->browse('GET', authorizeUrl($client, pkce()[1], $overrides));
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toStartWith(CALLBACK.'?')
        ->and($query)->toMatchArray(['error' => $error, 'state' => 'xyz', 'iss' => 'http://localhost']);
})->with([
    'no challenge' => [['code_challenge' => null], 'invalid_request'],
    'plain' => [['code_challenge_method' => 'plain'], 'invalid_request'],
    'token flow' => [['response_type' => 'token'], 'unsupported_response_type'],
    'another host' => [['resource' => 'https://elsewhere.example/mcp/fixture'], 'invalid_target'],
    'no server' => [['resource' => 'http://localhost/mcp/nope'], 'invalid_target'],
    'two servers, no resource' => [['resource' => null], 'invalid_target'],
]);

it('grants only the scopes it knows, and all of them when asked for none it knows', function () {
    person();
    $client = registered($this);
    signIn($this);

    $narrow = approve($this, authorizeUrl($client, ($narrow_pkce = pkce())[1], ['scope' => 'mcp:tools offline_access']));
    $unknown = approve($this, authorizeUrl($client, ($unknown_pkce = pkce())[1], ['scope' => 'openid']));

    $a = exchange($this, ['grant_type' => 'authorization_code', 'code' => $narrow['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $narrow_pkce[0]]);
    $b = exchange($this, ['grant_type' => 'authorization_code', 'code' => $unknown['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $unknown_pkce[0]]);

    expect($a->json('scope'))->toBe('mcp:tools')->and($b->json('scope'))->toBe('mcp:tools mcp:resources');
});

it('refuses a code with the wrong verifier, redirect URI, client or resource', function (string $field, mixed $value, string $error) {
    person();
    $client = registered($this);
    $other = registered($this, ['client_name' => 'Other']);
    [$verifier, $challenge] = pkce();
    signIn($this);
    $query = approve($this, authorizeUrl($client, $challenge));

    $params = ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier, 'resource' => RESOURCE];
    $params[$field] = $value === 'OTHER' ? $other : $value;
    $response = exchange($this, $params);

    expect($response->getStatusCode())->toBe(400)->and($response->json('error'))->toBe($error)
        ->and(AccessToken::query()->count())->toBe(0);
})->with([
    'verifier' => ['code_verifier', str_repeat('a', 43), 'invalid_grant'],
    'redirect' => ['redirect_uri', 'http://127.0.0.1:33418/other', 'invalid_grant'],
    'client' => ['client_id', 'OTHER', 'invalid_grant'],
    'resource' => ['resource', 'http://localhost/mcp/other', 'invalid_target'],
]);

it('takes a code once: presented again, it revokes what the first use issued', function () {
    person();
    $client = registered($this);
    [$verifier, $challenge] = pkce();
    signIn($this);
    $query = approve($this, authorizeUrl($client, $challenge));
    $params = ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier];

    $first = exchange($this, $params);
    $again = exchange($this, $params);

    expect($first->getStatusCode())->toBe(200)
        ->and($again->json('error'))->toBe('invalid_grant')
        ->and(endpointStatus($this, $first->json('access_token')))->toBe(401)
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $first->json('refresh_token'), 'client_id' => $client])->json('error'))->toBe('invalid_grant');
});

it('refuses an expired code', function () {
    person();
    $client = registered($this);
    [$verifier, $challenge] = pkce();
    signIn($this);
    $query = approve($this, authorizeUrl($client, $challenge));
    AuthorizationCode::query()->update(['expires_at' => now()->subSecond()]);

    expect(exchange($this, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier])->json('error'))->toBe('invalid_grant');
});

it('rotates a refresh token on every use, and ends the grant when a used one comes back', function () {
    [$client, $issued] = tokens($this);

    $refreshed = exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client]);
    $narrowed = exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshed->json('refresh_token'), 'client_id' => $client, 'scope' => 'mcp:tools']);

    expect($refreshed->getStatusCode())->toBe(200)
        ->and($refreshed->json('refresh_token'))->not->toBe($issued['refresh_token'])
        ->and($narrowed->json('scope'))->toBe('mcp:tools')
        // accepted, but without mcp:resources, which every request needs: a 403 to step up, not a 401
        ->and(endpointStatus($this, $narrowed->json('access_token')))->toBe(403);

    // the first refresh token, already rotated out, is replayed: every token of the grant stops working
    $replayed = exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client]);

    expect($replayed->json('error'))->toBe('invalid_grant')
        ->and(endpointStatus($this, $narrowed->json('access_token')))->toBe(401)
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $narrowed->json('refresh_token'), 'client_id' => $client])->json('error'))->toBe('invalid_grant');
});

it('refuses a refresh that widens the scopes, names another resource, or comes from another client', function () {
    [$client, $issued] = tokens($this);
    $other = registered($this, ['client_name' => 'Other']);

    exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client, 'scope' => 'mcp:tools']);
    RefreshToken::query()->update(['revoked_at' => null, 'abilities' => ['mcp:tools']]);

    expect(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client, 'scope' => 'mcp:tools mcp:resources'])->json('error'))->toBe('invalid_scope')
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client, 'resource' => 'http://localhost/mcp/other'])->json('error'))->toBe('invalid_target')
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $other])->json('error'))->toBe('invalid_grant');
});

it('gives no refresh token to a client that did not register for one', function () {
    person();
    $client = registered($this, ['grant_types' => ['authorization_code']]);
    [$verifier, $challenge] = pkce();
    signIn($this);
    $query = approve($this, authorizeUrl($client, $challenge));

    $response = exchange($this, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $client, 'code_verifier' => $verifier]);

    expect($response->json())->toHaveKey('access_token')->not->toHaveKey('refresh_token')
        ->and(exchange($this, ['grant_type' => 'password', 'client_id' => $client])->json('error'))->toBe('unsupported_grant_type');
});

it('makes a confidential client authenticate with its secret, by HTTP Basic or in the body', function () {
    person();
    $registration = $this->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['redirect_uris' => [CALLBACK], 'grant_types' => ['authorization_code', 'refresh_token']]))->json();
    [$verifier, $challenge] = pkce();
    signIn($this);
    $query = approve($this, authorizeUrl($registration['client_id'], $challenge));
    $params = ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'code_verifier' => $verifier];

    $without = exchange($this, [...$params, 'client_id' => $registration['client_id']]);
    $wrong = $this->call('POST', '/oauth/token', $params, [], [], ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($registration['client_id'].':nope')]);
    $basic = $this->call('POST', '/oauth/token', $params, [], [], ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($registration['client_id'].':'.$registration['client_secret'])]);
    $refreshed = exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $basic->json('refresh_token'), 'client_id' => $registration['client_id'], 'client_secret' => $registration['client_secret']]);

    expect($registration['token_endpoint_auth_method'])->toBe('client_secret_basic')
        ->and($registration['client_secret_expires_at'])->toBe(0)
        ->and([$without->getStatusCode(), $without->json('error')])->toBe([401, 'invalid_client'])
        ->and($wrong->getStatusCode())->toBe(401)->and($wrong->headers->get('WWW-Authenticate'))->toBe('Basic realm="mcp"')
        ->and($basic->getStatusCode())->toBe(200)
        ->and($refreshed->getStatusCode())->toBe(200);
});

it('refuses a registration with an unsafe redirect URI or grants it does not offer', function (array $metadata, string $error) {
    $response = $this->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($metadata));

    expect($response->getStatusCode())->toBe(400)->and($response->json('error'))->toBe($error);
})->with([
    'plain http' => [['redirect_uris' => ['http://example.com/cb']], 'invalid_redirect_uri'],
    'javascript' => [['redirect_uris' => ['javascript:alert(1)']], 'invalid_redirect_uri'],
    'fragment' => [['redirect_uris' => ['https://example.com/cb#x']], 'invalid_redirect_uri'],
    'none' => [['redirect_uris' => []], 'invalid_redirect_uri'],
    'implicit' => [['redirect_uris' => [CALLBACK], 'grant_types' => ['implicit']], 'invalid_client_metadata'],
    'not an object' => [[CALLBACK], 'invalid_client_metadata'],
]);

it('lets a native client come back on any loopback port', function () {
    person();
    $client = registered($this);
    [$verifier, $challenge] = pkce();
    signIn($this);

    $query = approve($this, authorizeUrl($client, $challenge, ['redirect_uri' => 'http://127.0.0.1:50123/callback']));

    expect(exchange($this, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => 'http://127.0.0.1:50123/callback', 'client_id' => $client, 'code_verifier' => $verifier])->getStatusCode())->toBe(200);
});

it('revokes a whole grant by its refresh token, and one access token by itself, for the client that holds them', function () {
    [$client, $issued] = tokens($this);
    $other = registered($this, ['client_name' => 'Other']);

    $this->call('POST', '/oauth/revoke', ['token' => $issued['access_token'], 'client_id' => $other])->assertStatus(200);
    $kept = endpointStatus($this, $issued['access_token']);
    $this->call('POST', '/oauth/revoke', ['token' => $issued['refresh_token'], 'client_id' => $client])->assertStatus(200);

    expect($kept)->toBe(404)
        ->and(endpointStatus($this, $issued['access_token']))->toBe(401)
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $issued['refresh_token'], 'client_id' => $client])->json('error'))->toBe('invalid_grant')
        ->and($this->call('POST', '/oauth/revoke', ['token' => 'unknown', 'client_id' => $client])->getStatusCode())->toBe(200);
});

it('asks a client metadata document who the client is, and shows the person its host', function () {
    $document = 'https://client.example/mcp-client.json';
    app()->registerInstance(Clients::class, new class(app('http')) extends Clients {
        protected function addresses(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    app('http')->fake([$document => Http::response(['client_id' => $document, 'client_name' => 'Example Agent', 'redirect_uris' => [CALLBACK], 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none'])]);
    person();
    [$verifier, $challenge] = pkce();
    signIn($this);

    $consent = $this->browse('GET', authorizeUrl($document, $challenge));
    $query = approve($this, authorizeUrl($document, $challenge));
    $response = exchange($this, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => CALLBACK, 'client_id' => $document, 'code_verifier' => $verifier]);

    expect($consent->getContent())->toContain('Allow Example Agent')->toContain('identifies itself as <strong>client.example</strong>')
        ->and($response->getStatusCode())->toBe(200)->and($response->json())->toHaveKey('refresh_token')
        ->and(exchange($this, ['grant_type' => 'refresh_token', 'refresh_token' => $response->json('refresh_token'), 'client_id' => $document])->getStatusCode())->toBe(200);
});

it('refuses a client metadata document on a private address, naming another client, or too large', function (string $address, array $document, string $problem) {
    $url = 'https://client.example/mcp-client.json';
    app()->registerInstance(Clients::class, new class(app('http'), $address) extends Clients {
        public function __construct(Http $http, private readonly string $address)
        {
            parent::__construct($http);
        }

        protected function addresses(string $host): array
        {
            return [$this->address];
        }
    });
    app('http')->fake([$url => Http::response(['client_id' => $url, 'redirect_uris' => [CALLBACK], ...$document])]);
    person();
    signIn($this);

    $response = $this->browse('GET', authorizeUrl($url, pkce()[1]));

    expect($response->getStatusCode())->toBe(400)->and($response->getContent())->toContain($problem);
})->with([
    'private' => ['10.0.0.5', [], 'does not resolve to a public address'],
    'loopback' => ['127.0.0.1', [], 'does not resolve to a public address'],
    'another client' => ['93.184.216.34', ['client_id' => 'https://evil.example/c.json'], 'names a different client_id'],
    'a secret' => ['93.184.216.34', ['token_endpoint_auth_method' => 'client_secret_basic'], 'only none'],
    'too large' => ['93.184.216.34', ['padding' => str_repeat('x', 6000)], 'larger than 5 KiB'],
]);

it('serves no registration endpoint and no metadata documents when they are turned off', function () {
    oauthReboot($this, ['registration' => false, 'client_metadata_documents' => false]);
    person();
    signIn($this);

    $metadata = $this->get('/.well-known/oauth-authorization-server')->json();

    expect($metadata)->not->toHaveKey('registration_endpoint')
        ->and($metadata['client_id_metadata_document_supported'])->toBeFalse()
        ->and($this->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->getStatusCode())->toBe(404)
        ->and($this->browse('GET', authorizeUrl('https://client.example/c.json', pkce()[1]))->getContent())->toContain('not accepted here');
});

it('sends a person to the app\'s own login page when it names one', function () {
    oauthReboot($this, ['login' => '/account/sign-in']);
    $client = registered($this);

    $this->browse('GET', authorizeUrl($client, pkce()[1]))->assertRedirect('http://localhost/account/sign-in');
    expect($this->get('/oauth/login')->getStatusCode())->toBe(404);
});

it('refuses to boot when the token guard would not accept the tokens it issues', function () {

    expect(fn () => oauthReboot($this, authorization: ['guard' => 'header']))
        ->toThrow(RuntimeException::class, 'does not use the mcp-token driver');
});

it('lets browser clients read the metadata and call the token endpoint across origins', function () {
    $preflight = $this->call('OPTIONS', '/oauth/token', [], [], [], ['HTTP_ORIGIN' => 'https://inspector.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
    $metadata = $this->call('GET', '/.well-known/oauth-authorization-server', [], [], [], ['HTTP_ORIGIN' => 'https://inspector.example']);

    expect($preflight->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($metadata->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

it('answers each consent page once, and keeps only the newest ten waiting', function () {
    person();
    $client = registered($this);
    signIn($this);
    $ids = [];

    foreach (range(1, 11) as $i) {
        preg_match('/name="request" value="([0-9a-f]{32})"/', $this->browse('GET', authorizeUrl($client, pkce()[1]))->getContent(), $match);
        $ids[] = $match[1];
    }

    $oldest = $this->browse('POST', '/oauth/authorize', ['request' => $ids[0], 'decision' => 'approve']);
    $newest = $this->browse('POST', '/oauth/authorize', ['request' => $ids[10], 'decision' => 'approve']);
    $again = $this->browse('POST', '/oauth/authorize', ['request' => $ids[10], 'decision' => 'approve']);

    expect($oldest->getStatusCode())->toBe(400)->and($oldest->getContent())->toContain('expired or was already answered')
        ->and($newest->getStatusCode())->toBe(302)
        ->and($again->getStatusCode())->toBe(400)
        ->and(AuthorizationCode::query()->count())->toBe(1);
});
