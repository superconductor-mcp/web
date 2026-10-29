<?php

// DNS rebinding: the Host header, and Origin when the client sends one, must name an allowed host.

it('refuses a Host that is not allowed with 403', function () {
    $server = serving($this);

    $refused = mcp($this, $server, 'POST', ['Host' => 'evil.example:8080'], rpc(0, 'initialize'));

    expect($refused['status'])->toBe(403)->and($refused['body'])->toContain('evil.example');
});

it('refuses an Origin that is not allowed with 403, even when the Host is', function () {
    $server = serving($this);

    $refused = mcp($this, $server, 'POST', ['Origin' => 'https://evil.example'], rpc(0, 'initialize'));

    expect($refused['status'])->toBe(403)->and($refused['body'])->toContain('evil.example');
});

it('lets an allowed Host and Origin through, ports ignored and case folded', function () {
    $server = serving($this);

    [$id, $answer] = mcpInitialize($this, $server);
    $local = mcp($this, $server, 'POST', ['Host' => 'LOCALHOST:9999', 'Origin' => 'http://localhost:3000', ...mcpSession($id)], rpc(1, 'ping'));
    $v6 = mcp($this, $server, 'POST', ['Host' => '[::1]:8080', ...mcpSession($id)], rpc(2, 'ping'));

    expect($answer['status'])->toBe(200)
        ->and($local['status'])->toBe(200)
        ->and($v6['status'])->toBe(200);
});

it('checks the Host of every method, not only POST', function () {
    $server = serving($this);

    expect(mcp($this, $server, 'GET', ['Host' => 'evil.example'])['status'])->toBe(403)
        ->and(mcp($this, $server, 'DELETE', ['Host' => 'evil.example'])['status'])->toBe(403);
});

it('allows any host when allowed_hosts holds *', function () {
    config(['sse.allowed_hosts' => ['*']]);
    $server = serving($this);

    $answer = mcp($this, $server, 'POST', ['Host' => 'anything.example', 'Origin' => 'https://anywhere.example'], [
        'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']],
    ]);

    expect($answer['status'])->toBe(200);
});

it('allows the hosts the config names', function () {
    config(['sse.allowed_hosts' => ['mcp.internal']]);
    $server = serving($this);

    $allowed = mcp($this, $server, 'POST', ['Host' => 'mcp.internal:80'], rpc(0, 'initialize'));
    $local = mcp($this, $server, 'POST', ['Host' => 'localhost'], rpc(0, 'initialize'));

    expect($allowed['status'])->not->toBe(403)->and($local['status'])->toBe(403);
});
