<?php

use Mariner\Auth\GenericUser;
use SuperconductorMCP\SSE\Auth\AccessToken;
use SuperconductorMCP\SSE\Auth\ProtectedResource;
use SuperconductorMCP\SSE\Http\Middleware\Authenticate;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Http\Middleware\ValidateHost;
use SuperconductorMCP\SSE\Http\Middleware\ValidateProtocolVersion;
use SuperconductorMCP\SSE\Tests\Fixtures\McpApp;

// With sse.authorization.guard set the endpoint is an OAuth protected resource (RFC 9728): a bearer token
// the guard accepts on every request, a 401 that names the metadata, a 403 to step up scope.

/** Boot with authorization on, an in-memory database migrated from the published migrations, and two users. */
function authorized($test, array $authorization = []): void
{
    bootWithSse($test, ['authorization' => ['guard' => 'mcp', 'servers' => ['https://auth.example.com'], 'scopes' => [], ...$authorization]]);
    mcpDatabase($test);

    app('db')->table('users')->insert([
        ['name' => 'ada', 'email' => 'ada@example.com', 'password' => 'x'],
        ['name' => 'grace', 'email' => 'grace@example.com', 'password' => 'x'],
    ]);
}

/** A token for user $id on $server's endpoint as $server_socket serves it. */
function tokenFor($server_socket, int $id = 1, array $scopes = [], string $server = 'fixture', ?DateTimeInterface $expires = null): string
{
    return AccessToken::issue(new GenericUser(['id' => $id]), ProtectedResource::uri($server, "http://127.0.0.1:{$server_socket->port()}"), $scopes, $expires);
}

function bearer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

it('challenges a request with no token, naming the metadata, which says who issues tokens', function () {
    authorized($this, ['scopes' => ['mcp:tools']]);
    $server = serving($this);
    $base = "http://127.0.0.1:{$server->port()}";

    [$id, $answer] = mcpInitialize($this, $server);
    $metadata = mcpExchange($this, $server, mcpRaw($server, 'GET', '/.well-known/oauth-protected-resource/mcp/fixture'));

    expect($id)->toBeNull()
        ->and($answer['status'])->toBe(401)
        ->and($answer['headers']['www-authenticate'])->toBe("Bearer scope=\"mcp:tools\", resource_metadata=\"{$base}/.well-known/oauth-protected-resource/mcp/fixture\"")
        ->and($metadata['status'])->toBe(200)
        ->and(json_decode($metadata['body'], true))->toBe([
            'resource' => "{$base}/mcp/fixture",
            'authorization_servers' => ['https://auth.example.com'],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp:tools'],
        ])
        ->and(mcpExchange($this, $server, mcpRaw($server, 'GET', '/.well-known/oauth-protected-resource/mcp/nope'))['status'])->toBe(404);
});

it('refuses an unknown, expired or other-server token as invalid_token', function () {
    authorized($this);
    $server = serving($this);

    foreach (['nonsense', tokenFor($server, expires: now()->subMinute()), tokenFor($server, server: 'other')] as $token) {
        [, $answer] = mcpInitialize($this, $server, headers: bearer($token));

        expect($answer['status'])->toBe(401)
            ->and($answer['headers']['www-authenticate'])->toStartWith('Bearer error="invalid_token", resource_metadata=');
    }
});

it('asks for the missing scope with a 403 insufficient_scope', function () {
    authorized($this, ['scopes' => ['mcp:tools', 'mcp:admin']]);
    $server = serving($this);

    [, $answer] = mcpInitialize($this, $server, headers: bearer(tokenFor($server, scopes: ['mcp:tools'])));
    [$id] = mcpInitialize($this, $server, headers: bearer(tokenFor($server, scopes: ['mcp:tools', 'mcp:admin'])));

    expect($answer['status'])->toBe(403)
        ->and($answer['headers']['www-authenticate'])->toStartWith('Bearer error="insufficient_scope", scope="mcp:tools mcp:admin", resource_metadata=')
        ->and($id)->toMatch('/^[0-9a-f]{32}$/');
});

it('serves a session to the user who opened it, with any of their tokens, and no one else', function () {
    authorized($this);
    $server = serving($this);
    $ada = bearer(tokenFor($server));

    [$id] = mcpInitialize($this, $server, headers: $ada);
    $initialized = mcp($this, $server, 'POST', [...mcpSession($id), ...$ada], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    $again = mcp($this, $server, 'POST', [...mcpSession($id), ...bearer(tokenFor($server))], rpc(1, 'ping'));
    $grace = mcp($this, $server, 'POST', [...mcpSession($id), ...bearer(tokenFor($server, 2))], rpc(2, 'ping'));
    $nobody = mcp($this, $server, 'POST', mcpSession($id), rpc(3, 'ping'));

    expect($initialized['status'])->toBe(202)
        ->and($again['status'])->toBe(200)
        ->and($grace['status'])->toBe(404)
        ->and($nobody['status'])->toBe(401)
        ->and(AccessToken::query()->whereNotNull('last_used_at')->count())->toBe(3);
});

it('takes tokens from a guard the app names instead', function () {
    authorized($this, ['guard' => 'header']);
    config(['auth.guards.header' => ['driver' => 'header']]);
    $this->app['auth']->viaRequest('header', fn ($request) => $request->bearerToken() === 'let-me-in' ? new GenericUser(['id' => 7]) : null);
    $server = serving($this);

    [$id] = mcpInitialize($this, $server, headers: bearer('let-me-in'));
    [, $refused] = mcpInitialize($this, $server, headers: bearer(tokenFor($server)));

    expect($id)->toMatch('/^[0-9a-f]{32}$/')->and($refused['status'])->toBe(401);
});

it('leaves the endpoint and its metadata path alone with no guard', function () {
    $server = serving($this);

    [$id] = mcpInitialize($this, $server);

    expect($id)->toMatch('/^[0-9a-f]{32}$/')
        ->and(mcpExchange($this, $server, mcpRaw($server, 'GET', '/.well-known/oauth-protected-resource/mcp/fixture'))['status'])->toBe(404);
});

it('refuses to boot with a guard but no Authenticate in the middleware, rather than leave the endpoint open', function () {
    expect(fn () => bootWithSse($this, [
        'authorization' => ['guard' => 'mcp', 'servers' => [], 'scopes' => []],
        'middleware' => ['host' => ValidateHost::class, 'protocol' => ValidateProtocolVersion::class, 'session' => ResolveSession::class],
    ]))->toThrow(RuntimeException::class, 'sse.middleware has no '.Authenticate::class);
});

it('publishes its migrations into the app dated, tokens first, and never twice', function () {
    $kernel = app(Voyager\Contracts\Console\Kernel::class);
    $publish = fn () => $kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['command' => 'vendor:publish', '--tag' => 'sse-migrations']), new Symfony\Component\Console\Output\NullOutput);

    try {
        $publish();
        $first = array_map('basename', glob(database_path('migrations/*.php')));
        $publish();
        $second = array_map('basename', glob(database_path('migrations/*.php')));
    } finally {
        (new Voyager\Filesystem\Filesystem)->deleteDirectory(database_path());
    }

    expect($first)->toHaveCount(2)
        ->and($first[0])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mcp_access_tokens_table\.php$/')
        ->and($first[1])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mcp_oauth_tables\.php$/')
        ->and($second)->toBe($first)
        ->and(glob(dirname(__DIR__, 2).'/database/migrations/[0-9]*'))->toBe([]);
});

it('keeps serve\'s auth guards when it is registered before serve', function () {
    McpApp::tearDown($this);
    McpApp::$providers = [
        SuperconductorMCP\SSE\Providers\SSEServiceProvider::class,
        Fabricate\Core\Providers\SuperconductorMCPServiceProvider::class,
        Mariner\Core\Providers\MarinerServiceProvider::class,
        SuperconductorMCP\SSE\Tests\Fixtures\FixtureServersProvider::class,
    ];
    $this->app = McpApp::boot();
    $this->loop = $this->app['event-loop'];

    expect(config('auth.guards.web.driver'))->toBe('session')
        ->and(config('auth.guards.mcp.driver'))->toBe('mcp-token')
        ->and(config('auth.defaults.guard'))->toBe('web');
});
