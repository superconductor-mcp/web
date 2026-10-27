<?php

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientStatus;
use Fabricate\Client\RequestFailed;
use SuperconductorMCP\SSE\Client\HttpConnection;

function httpClient(array $entry = [], string $script = 'http-server.php', string $path = '/mcp', string ...$args): array
{
    [$process, $port, $stdout] = spawnFixture($script, ...$args);
    $loop = testLoop();
    bareApp([], $loop);
    $client = new MCPClient('bench', $loop);
    $client->attach(new HttpConnection(['type' => 'http', 'url' => "http://127.0.0.1:{$port}{$path}", ...$entry], $loop, $client));

    return [$client, $loop, $process, $stdout];
}

afterEach(function () {
    foreach ($this->processes ?? [] as $process) {
        proc_terminate($process);
        proc_close($process);
    }
});

it('posts every message to the one endpoint, keeps the session id, and ends the session on close', function () {
    [$client, $loop, $process, $stdout] = httpClient();
    $this->processes = [$process];

    $client->open();
    expect($client->ready()->wait())->toBe($client)
        ->and($client->protocolVersion())->toBe('2025-03-26')
        ->and($client->serverInfo()['name'])->toBe('http-server')
        ->and(array_column($client->tools(), 'name'))->toBe(['add']);

    // the fixture answers 400 to any request after initialize that lacks the session id, so these prove it is sent
    $client->ping()->wait();
    expect($client->callTool('add', ['a' => 2, 'b' => 3])->wait()->content[0]['text'])->toBe('5');

    // a GET answered 405 means no stream, and that is fine
    expect($client->status())->toBe(ClientStatus::Connected);

    $client->close();
    $loop->until(function () use ($client): bool { return $client->closed(); });
    expect($client->status())->toBe(ClientStatus::NotConnected);

    // the fixture prints the DELETE it took, with the session id the client sent
    $printed = '';
    $loop->until(function () use ($stdout, &$printed): bool { $printed .= (string) stream_get_contents($stdout); return str_contains($printed, 'DELETE'); });
    expect($printed)->toContain('DELETE s1');
});

it('reads a reply that arrives as an event stream, with what the server sent before it', function () {
    [$client, $loop, $process] = httpClient(args: '--stream');
    $this->processes = [$process];

    $client->open();
    $client->ready()->wait();

    expect($client->callTool('add', ['a' => 1, 'b' => 1])->wait()->content[0]['text'])->toBe('2')
        ->and(json_encode(log_entries()))->toContain('half way');
});

it('listens on a GET stream, answers the server there, and resumes from the last event id when it drops', function () {
    [$client, $loop, $process] = httpClient(args: '--listen');
    $this->processes = [$process];

    $client->open();
    $client->ready()->wait();

    $loop->until(function (): bool { return str_contains(json_encode(log_entries()), 'resumed from 1'); });
    expect(json_encode(log_entries()))->toContain('mcp.incoming ping')
        ->and($client->status())->toBe(ClientStatus::Connected);
});

it('falls back to 2024-11-05 HTTP with SSE when the endpoint refuses the initialize POST', function () {
    [$client, $loop, $process] = httpClient([], 'sse-server.php', '/sse');
    $this->processes = [$process];

    $client->open();
    expect($client->ready()->wait())->toBe($client)
        ->and($client->serverInfo()['name'])->toBe('sse-server');
    expect($client->callTool('noop', ['a' => 1])->wait()->content[0]['text'])->toBe('{"a":1}');

    $client->close();
    $loop->until(function () use ($client): bool { return $client->closed(); });
    expect($client->status())->toBe(ClientStatus::NotConnected);
});

it('fails with the status when the server refuses, with or without the fallback', function () {
    [$client, $loop, $process] = httpClient(['fallback' => false], args: '--refuse');
    $this->processes = [$process];

    $client->open();
    $loop->until(function () use ($client): bool { return $client->status() !== ClientStatus::Connecting; });
    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('401');
    expect(fn () => $client->ready()->wait())->toThrow(RequestFailed::class);

    [$client, $loop, $process] = httpClient(args: '--refuse');
    $this->processes[] = $process;

    $client->open();
    $loop->until(function () use ($client): bool { return $client->status() !== ClientStatus::Connecting; });
    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('401');
});

it('turns into an error when the server ends the session', function () {
    [$client, $loop, $process] = httpClient(args: '--expire');
    $this->processes = [$process];

    $client->open();
    $client->ready()->wait();

    expect(fn () => $client->ping()->wait())->toThrow(RequestFailed::class);
    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('session');
});

it('fails when the server goes away mid-session', function () {
    [$client, $loop, $process] = httpClient();
    $this->processes = [$process];

    $client->open();
    $client->ready()->wait();
    proc_terminate($process);
    usleep(50_000);

    expect(fn () => $client->ping()->wait())->toThrow(RequestFailed::class);
    expect($client->status())->toBe(ClientStatus::Error);
});
