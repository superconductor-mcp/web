<?php

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientStatus;
use Fabricate\Client\RequestFailed;
use SuperconductorMCP\SSE\Client\SseConnection;

function sseClient(string ...$args): array
{
    [$process, $port] = fixtureServer(...$args);
    $loop = testLoop();
    bareApp([], $loop);
    $client = new MCPClient('bench', $loop);
    $client->attach(new SseConnection(['type' => 'sse', 'url' => "http://127.0.0.1:{$port}/sse"], $loop, $client));

    return [$client, $loop, $process];
}

afterEach(function () {
    foreach ($this->processes ?? [] as $process) {
        proc_terminate($process);
        proc_close($process);
    }
});

it('opens the stream, posts to the endpoint it names, and reads the replies as events', function () {
    [$client, $loop, $process] = sseClient();
    $this->processes = [$process];

    $client->open();
    expect($client->ready()->wait())->toBe($client)
        ->and($client->serverInfo()['name'])->toBe('sse-server')
        ->and(array_column($client->tools(), 'name'))->toBe(['noop']);

    $client->ping()->wait();
    expect($client->callTool('noop', ['a' => 1])->wait()->content[0]['text'])->toBe('{"a":1}');

    // the server's own request arrives on the stream and is answered with a POST
    $client->callTool('ping-me')->wait();
    $loop->until(function () use ($client): bool { return str_contains(json_encode(log_entries()), 'mcp.incoming ping'); });

    $client->close();
    expect($client->closed())->toBeTrue()->and($client->status())->toBe(ClientStatus::NotConnected);
});

it('reads a stream framed with chunked transfer encoding', function () {
    [$client, $loop, $process] = sseClient('--chunked');
    $this->processes = [$process];

    $client->open();
    expect($client->ready()->wait())->toBe($client);
    $client->ping()->wait();
    $client->close();
});

it('fails with the status when the server refuses the stream', function () {
    [$client, $loop, $process] = sseClient('--refuse');
    $this->processes = [$process];

    $client->open();
    $loop->until(function () use ($client): bool { return $client->status() !== ClientStatus::Connecting; });

    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('401');
    expect(fn () => $client->ready()->wait())->toThrow(RequestFailed::class);
});

it('fails when the server does not answer with an event stream', function () {
    [$client, $loop, $process] = sseClient('--plain');
    $this->processes = [$process];

    $client->open();
    $loop->until(function () use ($client): bool { return $client->status() !== ClientStatus::Connecting; });

    expect($client->lastError())->toContain('text/plain');
});

it('fails when the server goes away mid-session', function () {
    [$client, $loop, $process] = sseClient();
    $this->processes = [$process];

    $client->open();
    $client->ready()->wait();
    proc_terminate($process);

    $loop->until(function () use ($client): bool { return $client->status() !== ClientStatus::Connected; });
    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('closed');
});

it('names an entry without a usable url', function () {
    $loop = testLoop();
    bareApp([], $loop);
    $client = new MCPClient('bench', $loop);
    $client->attach(new SseConnection(['type' => 'sse', 'url' => 'nope'], $loop, $client));

    $client->open();
    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('url');
});
