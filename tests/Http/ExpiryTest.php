<?php

// Idle sessions are swept whenever the registry is used (a session is opened or looked up).

it('forgets a session that has gone idle for longer than sse.session.idle_timeout', function () {
    config(['sse.session.idle_timeout' => 0.05]);
    $server = serving($this);
    [$idle] = mcpInitialize($this, $server);

    mcpWait($this, 0.15);

    expect(mcp($this, $server, 'POST', mcpSession($idle), rpc(1, 'ping'))['status'])->toBe(404);
});

it('keeps a session that is used within the timeout', function () {
    config(['sse.session.idle_timeout' => 0.3]);
    $server = serving($this);
    [$id] = mcpInitialize($this, $server);

    foreach ([1, 2, 3] as $n) {
        mcpWait($this, 0.1);
        expect(mcp($this, $server, 'POST', mcpSession($id), rpc($n, 'ping'))['status'])->toBe(200);
    }
});

it('does not forget a session with an open stream, however long it is idle', function () {
    config(['sse.session.idle_timeout' => 0.05]);
    $server = serving($this);
    [$streaming] = mcpInitialize($this, $server);
    [$idle] = mcpInitialize($this, $server);

    $stream = clientOf($this, $server);
    stream_set_blocking($stream, true);
    fwrite($stream, mcpRaw($server, 'GET', '/mcp/fixture', mcpSession($streaming)));
    stream_set_blocking($stream, false);
    mcpRead($this, [$stream], fn (array $r) => str_contains($r[0], "\r\n\r\n"));

    mcpWait($this, 0.2);

    // the sweep runs on these lookups: the idle session goes, the one with a stream stays
    expect(mcp($this, $server, 'POST', mcpSession($idle), rpc(1, 'ping'))['status'])->toBe(404)
        ->and(mcp($this, $server, 'POST', mcpSession($streaming), rpc(1, 'ping'))['status'])->toBe(200);
});
