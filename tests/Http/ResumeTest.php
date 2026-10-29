<?php

use Fabricate\Contracts\NutsAndBolts\LogLevel;
use Fabricate\NutsAndBolts\Messages\Tools\ToolListChangedNotification;
use SuperconductorMCP\SSE\Sessions\HttpSessions;

// 2025-11-25 (SEP-1699): every event has an id naming its stream, a new stream opens with an id and no data,
// and a client that lost a stream resumes it with a GET and Last-Event-ID, getting what it missed on that stream only.

/** A session on $version that has finished its handshake. */
function resumableSession($test, $server, string $version = '2025-11-25', array $capabilities = []): string
{
    [$id] = mcpInitialize($test, $server, capabilities: $capabilities, version: $version);
    mcp($test, $server, 'POST', mcpSession($id, $version), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    return $id;
}

/** A socket that has sent $raw and stays open for reading. */
function opened($test, $server, string $raw)
{
    $socket = clientOf($test, $server);
    stream_set_blocking($socket, true);
    fwrite($socket, $raw);
    stream_set_blocking($socket, false);

    return $socket;
}

it('opens a 2025-11-25 stream with an id and no data, and numbers every event on its stream', function () {
    $server = serving($this);
    $id = resumableSession($this, $server);

    $answer = mcp($this, $server, 'POST', mcpSession($id, '2025-11-25'), rpc(1, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]));
    [$stream] = explode(':', $answer['ids'][0]);

    expect($answer['body'])->toStartWith("id: {$stream}:0\ndata: \n\n")
        ->and($answer['ids'])->toBe(["{$stream}:0", "{$stream}:1"])
        ->and($answer['events'][0]['result']['content'][0]['text'])->toBe('hi');

    // ids are unique across the session's streams
    $again = mcp($this, $server, 'POST', mcpSession($id, '2025-11-25'), rpc(2, 'ping'));
    expect(explode(':', $again['ids'][0])[0])->not->toBe($stream);
});

it('does not prime a stream for a session before 2025-11-25, whose client may not parse an empty event', function () {
    $server = serving($this);
    $id = resumableSession($this, $server, '2025-06-18');

    $answer = mcp($this, $server, 'POST', mcpSession($id), rpc(1, 'ping'));

    expect($answer['body'])->toStartWith('id: ')->and($answer['body'])->not->toContain("data: \n")
        ->and($answer['ids'])->toHaveCount(1);
});

it('lets the call run on when its POST stream drops, and replays the rest of that stream on a resume', function () {
    $server = serving($this);
    $id = resumableSession($this, $server, capabilities: ['elicitation' => (object) []]);

    $call = opened($this, $server, mcpRaw($server, 'POST', '/mcp/fixture', mcpSession($id, '2025-11-25'), rpc(1, 'tools/call', ['name' => 'ask', 'arguments' => (object) []])));
    $asked = mcpAnswer(mcpRead($this, [$call], fn (array $r) => mcpSaw($r[0], 'elicitation/create'))[0]);
    $question = collect($asked['events'])->firstWhere('method', 'elicitation/create');
    $last = end($asked['ids']);

    // the client loses the connection, then answers; the tool is not cancelled by the drop
    fclose($call);
    mcpWait($this, 0.05);
    expect(mcp($this, $server, 'POST', mcpSession($id, '2025-11-25'), ['jsonrpc' => '2.0', 'id' => $question['id'], 'result' => ['action' => 'accept', 'content' => ['name' => 'Ada']]])['status'])->toBe(202);

    $resumed = mcp($this, $server, 'GET', [...mcpSession($id, '2025-11-25'), 'Last-Event-ID' => $last]);

    // only what came after the last event it saw, on that stream: the question is not sent again
    expect($resumed['status'])->toBe(200)
        ->and($resumed['events'])->toHaveCount(1)
        ->and($resumed['events'][0]['id'])->toBe(1)
        ->and($resumed['events'][0]['result']['content'][0]['text'])->toBe('hello Ada')
        ->and($this->app->make(HttpSessions::class)->find($id, 'fixture')->event_streams)->toBe([]);
});

it('resumes a dropped GET stream from the id it names, replaying what it already sent and what queued meanwhile', function () {
    $server = serving($this);
    $id = resumableSession($this, $server);
    $mcp = $this->app->make(HttpSessions::class)->find($id, 'fixture')->bag()->server_configuration;

    $listen = opened($this, $server, mcpRaw($server, 'GET', '/mcp/fixture', mcpSession($id, '2025-11-25')));
    $opened = mcpRead($this, [$listen], fn (array $r) => str_contains($r[0], "data: \n"))[0];
    $prime = mcpAnswer($opened)['ids'][0];

    $mcp->notify(new ToolListChangedNotification());
    mcpRead($this, [$listen], fn (array $r) => mcpSaw($opened.$r[0], 'notifications/tools/list_changed'));
    fclose($listen);
    mcpWait($this, 0.05);

    // queued while nobody listens: it waits in the server's queue, not in a stream no one reads
    $mcp->log(LogLevel::Info, 'while away');

    $resumed = opened($this, $server, mcpRaw($server, 'GET', '/mcp/fixture', [...mcpSession($id, '2025-11-25'), 'Last-Event-ID' => $prime]));
    $events = mcpAnswer(mcpRead($this, [$resumed], fn (array $r) => mcpSaw($r[0], 'notifications/message'))[0])['events'];

    expect(array_column($events, 'method'))->toBe(['notifications/tools/list_changed', 'notifications/message'])
        ->and($events[1]['params']['data'])->toBe('while away');
});

it('listens afresh for a Last-Event-ID that names no stream of the session', function () {
    $server = serving($this);
    $id = resumableSession($this, $server);

    $listen = opened($this, $server, mcpRaw($server, 'GET', '/mcp/fixture', [...mcpSession($id, '2025-11-25'), 'Last-Event-ID' => 'feedfacefeedface:3']));
    $opened = mcpRead($this, [$listen], fn (array $r) => str_contains($r[0], "data: \n"))[0];

    $this->app->make(HttpSessions::class)->find($id, 'fixture')->bag()->server_configuration->notify(new ToolListChangedNotification());
    $received = mcpRead($this, [$listen], fn (array $r) => mcpSaw($opened.$r[0], 'notifications/tools/list_changed'))[0];

    expect(mcpAnswer($opened)['headers'])->toHaveKey('content-type')
        ->and(mcpAnswer($opened.$received)['events'])->toHaveCount(1)
        ->and(mcpAnswer($opened)['ids'][0])->not->toStartWith('feedfacefeedface');
});
