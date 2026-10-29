<?php

use SuperconductorMCP\SSE\Http\Controllers\MessagesController;
use SuperconductorMCP\SSE\Http\Controllers\SessionController;
use SuperconductorMCP\SSE\Http\Controllers\StreamController;
use SuperconductorMCP\SSE\Tests\Fixtures\SwappedMessagesController;

it('answers with the controller config/sse.php names for a method', function () {
    bootWithSse($this, ['controllers' => [
        'messages' => SwappedMessagesController::class,
        'stream' => StreamController::class,
        'session' => SessionController::class,
    ]]);
    $server = serving($this);

    $answer = mcp($this, $server, 'POST', [], rpc(0, 'initialize'), 'fixture');

    expect($answer['status'])->toBe(200)->and($answer['body'])->toBe('swapped for fixture');
});

it('mounts the endpoint at sse.path', function () {
    bootWithSse($this, ['path' => 'tools/{server}/rpc']);
    $server = serving($this);

    $moved = mcpExchange($this, $server, mcpRaw($server, 'POST', '/tools/fixture/rpc', [], [
        'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']],
    ]));
    $old = mcp($this, $server, 'POST', [], rpc(0, 'initialize'));

    expect($moved['status'])->toBe(200)->and($moved['headers'])->toHaveKey('mcp-session-id')
        ->and($old['status'])->toBe(404);
});

it('runs only the middleware config/sse.php lists', function () {
    bootWithSse($this, ['middleware' => [
        'session' => SuperconductorMCP\SSE\Http\Middleware\ResolveSession::class,
    ]]);
    $server = serving($this);

    // no host check any more: a Host that the default config refuses is served
    $answer = mcp($this, $server, 'POST', ['Host' => 'evil.example'], [
        'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']],
    ]);

    expect($answer['status'])->toBe(200);
});

it('registers the routes under their names', function () {
    $routes = collect($this->app['router']->getRoutes()->getRoutes())->mapWithKeys(fn ($route) => [$route->getName() => $route->uri()]);

    expect($routes['mcp.messages'])->toBe('mcp/{server}')
        ->and($routes['mcp.stream'])->toBe('mcp/{server}')
        ->and($routes['mcp.session'])->toBe('mcp/{server}')
        ->and(config('sse.controllers.messages'))->toBe(MessagesController::class);
});
