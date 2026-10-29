<?php

use Fabricate\NutsAndBolts\Messages\Tools\ToolListChangedNotification;
use SuperconductorMCP\SSE\Sessions\HttpSessions;

/** A session that has finished its handshake. */
function readySession($test, $server): string
{
    [$id] = mcpInitialize($test, $server);
    mcp($test, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    return $id;
}

it('carries a notification the server queues outside any request on the GET stream', function () {
    $server = serving($this);
    $id = readySession($this, $server);

    $stream = clientOf($this, $server);
    stream_set_blocking($stream, true);
    fwrite($stream, mcpRaw($server, 'GET', '/mcp/fixture', mcpSession($id)));
    stream_set_blocking($stream, false);

    $opened = mcpRead($this, [$stream], fn (array $r) => str_contains($r[0], "\r\n\r\n"))[0];
    expect($opened)->toStartWith('HTTP/1.1 200')->and($opened)->toContain('text/event-stream');

    // queued by the application, with no request running
    $this->app->make(HttpSessions::class)->find($id, 'fixture')->bag()->server_configuration->notify(new ToolListChangedNotification());

    $received = mcpRead($this, [$stream], fn (array $r) => mcpSaw($opened.$r[0], 'notifications/tools/list_changed'))[0];
    $events = mcpAnswer($opened.$received)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['method'])->toBe('notifications/tools/list_changed');
});

it('keeps the session when the GET stream client hangs up, and the stream no longer counts', function () {
    $server = serving($this);
    $id = readySession($this, $server);
    $session = $this->app->make(HttpSessions::class)->find($id, 'fixture');

    $stream = clientOf($this, $server);
    stream_set_blocking($stream, true);
    fwrite($stream, mcpRaw($server, 'GET', '/mcp/fixture', mcpSession($id)));
    stream_set_blocking($stream, false);

    mcpRead($this, [$stream], fn () => $session->streams === 1);
    expect($session->streams)->toBe(1);

    fclose($stream);
    mcpRead($this, [], fn () => $session->streams === 0);

    expect($session->streams)->toBe(0)
        ->and($session->closed)->toBeFalse()
        ->and(mcp($this, $server, 'POST', mcpSession($id), rpc(7, 'ping'))['events'][0]['id'])->toBe(7);
});

it('ends the GET stream when the session is deleted', function () {
    $server = serving($this);
    $id = readySession($this, $server);

    $stream = clientOf($this, $server);
    stream_set_blocking($stream, true);
    fwrite($stream, mcpRaw($server, 'GET', '/mcp/fixture', mcpSession($id)));
    stream_set_blocking($stream, false);

    mcpRead($this, [$stream], fn (array $r) => str_contains($r[0], "\r\n\r\n"));

    expect(mcp($this, $server, 'DELETE', mcpSession($id))['status'])->toBe(204);

    mcpRead($this, [$stream], fn () => feof($stream));

    expect(feof($stream))->toBeTrue();
});
