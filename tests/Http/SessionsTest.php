<?php

it('answers initialize on an event stream with a session id, which later requests reuse', function () {
    $server = serving($this);

    [$id, $initialized] = mcpInitialize($this, $server);

    expect($initialized['status'])->toBe(200)
        ->and($initialized['headers']['content-type'])->toStartWith('text/event-stream')
        ->and($id)->toMatch('/^[0-9a-f]{32}$/')
        ->and($initialized['events'])->toHaveCount(1)
        ->and($initialized['events'][0]['id'])->toBe(0)
        ->and($initialized['events'][0]['result']['serverInfo'])->toBe(['name' => 'fixture', 'version' => '1.0.0'])
        ->and($initialized['events'][0]['result']['protocolVersion'])->toBe('2025-06-18');

    $handshake = mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    $pinged = mcp($this, $server, 'POST', mcpSession($id), rpc(1, 'ping'));

    expect($handshake['status'])->toBe(202)->and($handshake['body'])->toBe('')
        ->and($pinged['status'])->toBe(200)
        ->and($pinged['headers'])->not->toHaveKey('mcp-session-id')
        ->and($pinged['events'][0]['id'])->toBe(1);
});

it('answers a tool call on the event stream', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);
    mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    $called = mcp($this, $server, 'POST', mcpSession($id), rpc(2, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]));

    expect($called['status'])->toBe(200)
        ->and($called['events'])->toHaveCount(1)
        ->and($called['events'][0]['id'])->toBe(2)
        ->and($called['events'][0]['result']['content'][0]['text'])->toBe('hi');
});

it('refuses a request without a session, unless it initializes', function () {
    $server = serving($this);

    $ping = mcp($this, $server, 'POST', [], rpc(1, 'ping'));
    $get = mcp($this, $server, 'GET');
    $delete = mcp($this, $server, 'DELETE');

    expect($ping['status'])->toBe(400)
        ->and($get['status'])->toBe(400)
        ->and($delete['status'])->toBe(400);
});

it('answers 404 to a session id the server does not know, or one opened on another server', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);

    $unknown = mcp($this, $server, 'POST', mcpSession(str_repeat('0', 32)), rpc(1, 'ping'));
    $elsewhere = mcp($this, $server, 'POST', mcpSession($id), rpc(1, 'ping'), 'other');

    expect($unknown['status'])->toBe(404)->and($elsewhere['status'])->toBe(404);
});

it('answers 404 to initialize on a server that is not registered', function () {
    $server = serving($this);

    [$id, $answer] = mcpInitialize($this, $server, 'nowhere');

    expect($answer['status'])->toBe(404)->and($id)->toBeNull();
});

it('refuses an unsupported MCP-Protocol-Version with 400', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);

    $refused = mcp($this, $server, 'POST', [...mcpSession($id), 'MCP-Protocol-Version' => '1999-01-01'], rpc(1, 'ping'));

    expect($refused['status'])->toBe(400)->and($refused['body'])->toContain('1999-01-01');
});

it('refuses a batch on a 2025-06-18 session with 400', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);
    mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    $batch = mcp($this, $server, 'POST', mcpSession($id), message: null, body: json_encode([rpc(1, 'ping'), rpc(2, 'ping')]));

    expect($batch['status'])->toBe(400)->and($batch['body'])->toContain('Batches are not supported');
});

it('answers 406 and handles nothing when the client cannot take an event stream', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);
    $before = $this->app->make(SuperconductorMCP\SSE\Sessions\HttpSessions::class)->find($id, 'fixture')->bag()->their_ids;

    $refused = mcp($this, $server, 'POST', [...mcpSession($id), 'Accept' => 'application/json'], rpc(5, 'ping'));

    expect($refused['status'])->toBe(406)
        ->and($refused['events'])->toBe([])
        ->and($this->app->make(SuperconductorMCP\SSE\Sessions\HttpSessions::class)->find($id, 'fixture')->bag()->their_ids)->toBe($before)
        ->and(mcp($this, $server, 'GET', [...mcpSession($id), 'Accept' => 'application/json'])['status'])->toBe(406);
});

it('ends a session with DELETE, after which its id is unknown', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);

    $deleted = mcp($this, $server, 'DELETE', mcpSession($id));
    $again = mcp($this, $server, 'DELETE', mcpSession($id));
    $ping = mcp($this, $server, 'POST', mcpSession($id), rpc(1, 'ping'));

    expect($deleted['status'])->toBe(204)
        ->and($again['status'])->toBe(404)
        ->and($ping['status'])->toBe(404);
});
