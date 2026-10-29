<?php

use Mariner\Contracts\Server\HttpServer;
use SuperconductorMCP\SSE\Tests\Fixtures\McpApp;

// Each test boots the fixture app and serves it on a free port of the test's own loop.
uses()
    ->beforeEach(function () {
        $this->app = McpApp::boot();
        $this->loop = $this->app['event-loop'];
        $this->servers = [];
        $this->sockets = [];
    })
    ->afterEach(function () {
        foreach ($this->sockets as $socket) {
            is_resource($socket) && fclose($socket);
        }

        foreach ($this->servers as $server) {
            $server->stop();
        }

        McpApp::tearDown($this);
    })
    ->in('Http');

/** Boot the fixture app again with $sse as its config/sse.php: for config that is read while the app boots. */
function bootWithSse($test, array $sse): void
{
    McpApp::tearDown($test);
    McpApp::$sse = $sse;
    $test->app = McpApp::boot();
    $test->loop = $test->app['event-loop'];
}

/** Turn the loop for $seconds with nothing else to do. */
function mcpWait($test, float $seconds): void
{
    mcpRead($test, [], fn () => false, $seconds);
}

/** A server on a free port, attached to the test's loop, made through the HttpServer contract. */
function serving($test): HttpServer
{
    $server = $test->app->make(HttpServer::class, ['options' => ['port' => 0]])->attach($test->loop);
    $test->servers[] = $server;

    return $server;
}

/** A client socket to $server, non-blocking, so the loop keeps turning while it waits. */
function clientOf($test, HttpServer $server)
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$server->port()}", $code, $message, 2);
    stream_set_blocking($socket, false);

    return $test->sockets[] = $socket;
}

/**
 * The text of one request to the MCP endpoint of $server. A header given as null is left out.
 *
 * @param  array<string, string|null>  $headers
 */
function mcpRaw(HttpServer $server, string $method, string $path, array $headers = [], ?array $message = null, ?string $body = null): string
{
    $body ??= is_null($message) ? '' : json_encode($message);
    $headers = [
        'Host' => "127.0.0.1:{$server->port()}",
        'Accept' => 'application/json, text/event-stream',
        'Content-Type' => 'application/json',
        'Connection' => 'close',
        ...$headers,
    ];

    if ($body !== '') {
        $headers['Content-Length'] = (string) strlen($body);
    }

    $lines = ["{$method} {$path} HTTP/1.1"];

    foreach ($headers as $name => $value) {
        if (! is_null($value)) {
            $lines[] = "{$name}: {$value}";
        }
    }

    return implode("\r\n", $lines)."\r\n\r\n".$body;
}

/** Write $raw to $socket, then turn the loop reading every socket in $sockets until $until(array $received) says enough. */
function mcpRead($test, array $sockets, Closure $until, float $timeout = 5.0): array
{
    $received = array_fill_keys(array_keys($sockets), '');
    $deadline = microtime(true) + $timeout;
    $name = 'test.client.'.random_int(0, PHP_INT_MAX);
    $pace = $test->loop->every(0.001, fn () => null, $name);

    try {
        $test->loop->until(function () use ($sockets, &$received, $until, $deadline) {
            foreach ($sockets as $key => $socket) {
                while (($bytes = (string) fread($socket, 65_536)) !== '') {
                    $received[$key] .= $bytes;
                }
            }

            return $until($received) || microtime(true) > $deadline;
        });
    } finally {
        $pace->cancel();
        $test->loop->forget($name);
    }

    return $received;
}

/** Send $raw on a socket of its own and return the whole answer, once the server closes it. */
function mcpExchange($test, HttpServer $server, string $raw): array
{
    $socket = clientOf($test, $server);

    stream_set_blocking($socket, true);
    fwrite($socket, $raw);
    stream_set_blocking($socket, false);

    return mcpAnswer(mcpRead($test, [$socket], fn () => feof($socket))[0]);
}

/**
 * One request to the endpoint of the named server, as a client of the transport sends it.
 *
 * @param  array<string, string|null>  $headers
 */
function mcp($test, HttpServer $server, string $method, array $headers = [], ?array $message = null, string $name = 'fixture', ?string $body = null): array
{
    return mcpExchange($test, $server, mcpRaw($server, $method, "/mcp/{$name}", $headers, $message, $body));
}

/** Initialize a session on the named server; its id and the answer. */
function mcpInitialize($test, HttpServer $server, string $name = 'fixture', array $capabilities = [], string $version = '2025-06-18'): array
{
    $answer = mcp($test, $server, 'POST', [], [
        'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
        'params' => ['protocolVersion' => $version, 'capabilities' => (object) $capabilities, 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ], $name);

    return [$answer['headers']['mcp-session-id'] ?? null, $answer];
}

/** Headers that carry a session, and its protocol version, on later requests. */
function mcpSession(string $id, string $version = '2025-06-18'): array
{
    return ['Mcp-Session-Id' => $id, 'MCP-Protocol-Version' => $version];
}

/** A JSON-RPC request message. */
function rpc(int $id, string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params];
}

/** Whether the stream so far holds an event for $method (JSON escapes slashes, so a substring search would miss it). */
function mcpSaw(string $raw, string $method): bool
{
    return in_array($method, array_column(mcpAnswer($raw)['events'], 'method'), true);
}

/** The head and the decoded body of one response: status, lower-cased headers, body, and the JSON of each `data:` event. */
function mcpAnswer(string $raw): array
{
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    preg_match('#^HTTP/\d\.\d (\d{3})#', $lines[0], $status);
    $headers = [];

    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower($name)] = trim($value);
    }

    if (($headers['transfer-encoding'] ?? '') === 'chunked') {
        $decoded = '';

        while ($body !== '' && ($end = strpos($body, "\r\n")) !== false) {
            $size = hexdec(substr($body, 0, $end));

            if ($size === 0) {
                break;
            }

            $decoded .= substr($body, $end + 2, $size);
            $body = substr($body, $end + 2 + $size + 2);
        }

        $body = $decoded;
    }

    preg_match_all('/^data: (.*)$/m', $body, $data);

    return [
        'status' => (int) ($status[1] ?? 0),
        'headers' => $headers,
        'body' => $body,
        'events' => array_map(fn (string $json) => json_decode($json, true), $data[1]),
    ];
}
