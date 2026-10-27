<?php

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientStatus;
use Fabricate\Server\MCPServer;
use Fabricate\Server\ServerRepository;
use Fabricate\NutsAndBolts\ToolDefinition;
use Fabricate\NutsAndBolts\ProgressReporter;
use Fabricate\NutsAndBolts\Schema\TextProperty;
use Fabricate\NutsAndBolts\Schema\StringProperty;
use Fabricate\NutsAndBolts\Schema\ObjectSchema;
use Fabricate\NutsAndBolts\Attributes\ToolCall;
use Fabricate\NutsAndBolts\Messages\Tools\ToolListChangedNotification;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\SSE\Server\McpHttpServer;
use SuperconductorMCP\SSE\Client\HttpConnection;
use Fabricate\Mechanism\Session\Elicitation;
use Fabricate\NutsAndBolts\Messages\Elicitation\ElicitAnswer;

#[ToolCall]
final class EchoBack
{
    public function __construct(private readonly Loop $loop, private readonly ProgressReporter $progress) {}

    public function handle(string $text): TextProperty
    {
        // one hop on the loop, so the reply proves the fiber path and a progress line goes out ahead of it
        $this->progress->report(1, 2, 'half');
        $done = false;
        $this->loop->at(0.02, function () use (&$done): void { $done = true; });
        $this->loop->until(function () use (&$done): bool { return $done; });

        // a notification queued by the tool itself goes out after the reply, on the same stream
        app(MCPServer::class)->notify(new ToolListChangedNotification());

        return new TextProperty(strrev($text));
    }
}

final class BenchHttpServer extends MCPServer
{
    protected array $entity_info = ['name' => 'bench-http', 'version' => '1'];

    protected array $entity_capabilities = ['tools' => ['listChanged' => true]];

    public function tools(): array
    {
        return [
            new ToolDefinition('rev', 'Reverses text', ['text' => new StringProperty('text')], ['text'], EchoBack::class),
            new ToolDefinition('greet', action: function (): TextProperty {
                $answer = app(Elicitation::class)->ask('Name?', new ObjectSchema(['name' => new StringProperty()], ['name']));

                return new TextProperty($answer->accepted() ? "hello {$answer->content['name']}" : $answer->action->value);
            }),
        ];
    }
}

/** @return array{0: McpHttpServer, 1: \Voyager\IOPools\EventLoop, 2: string, 3: BenchHttpServer} */
function serve(array $web = []): array
{
    $loop = testLoop();
    $app = bareApp([], $loop, $web);
    $mcp = new BenchHttpServer();
    $server = new McpHttpServer($loop, new ServerRepository($app));
    $token = McpHttpServer::token();
    $server->start($mcp, 0, $token);

    return [$server, $loop, $token, $mcp];
}

// one raw HTTP/1.1 exchange against the server while the loop turns; returns [status, headers, body]
function raw(McpHttpServer $server, Loop $loop, string $method, string $path, array $headers = [], string $body = '', float $timeout = 2.0): array
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$server->port()}", $errno, $error, 1);
    stream_set_blocking($socket, false);
    $lines = ["{$method} {$path} HTTP/1.1", 'Host: 127.0.0.1', 'Content-Length: '.strlen($body)];

    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    fwrite($socket, implode("\r\n", $lines)."\r\n\r\n".$body);
    $in = '';
    $deadline = microtime(true) + $timeout;
    $loop->until(function () use ($socket, &$in, $deadline): bool {
        while (($chunk = fread($socket, 65536)) !== false && $chunk !== '') {
            $in .= $chunk;
        }

        return feof($socket) || microtime(true) > $deadline;
    });
    fclose($socket);

    [$head, $payload] = array_pad(explode("\r\n\r\n", $in, 2), 2, '');
    $head_lines = explode("\r\n", $head);
    $status = (int) (explode(' ', array_shift($head_lines))[1] ?? 0);
    $parsed = [];

    foreach ($head_lines as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $parsed[strtolower(trim($name))] = trim($value);
    }

    return [$status, $parsed, $payload];
}

afterEach(function () {
    $this->server?->stop();
});

it('refuses what is not a bearer-carrying request to the endpoint', function () {
    [$server, $loop, $token] = serve(['limits' => ['body' => 64, 'head_timeout' => 0.2]]);
    $this->server = $server;
    $auth = ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'];

    expect(raw($server, $loop, 'POST', '/other', $auth, '{}')[0])->toBe(404)
        ->and(raw($server, $loop, 'PUT', '/mcp', $auth, '{}')[0])->toBe(405)
        ->and(raw($server, $loop, 'POST', '/mcp', ['Content-Type' => 'application/json'], '{}')[0])->toBe(401)
        ->and(raw($server, $loop, 'POST', '/mcp', ['Content-Type' => 'application/json'], '{}')[1])->toHaveKey('www-authenticate')
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, 'Origin' => 'https://evil.example'], '{}')[0])->toBe(403)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, 'Origin' => 'http://localhost:3000'], '{"jsonrpc":"2.0","method":"notifications/initialized"}')[0])->toBe(400)
        ->and(raw($server, $loop, 'GET', '/mcp', $auth)[0])->toBe(400)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, 'Mcp-Session-Id' => 'nope'], '{}')[0])->toBe(404)
        ->and(raw($server, $loop, 'POST', '/mcp', $auth, 'not json')[0])->toBe(400)
        ->and(raw($server, $loop, 'POST', '/mcp', $auth, str_repeat('x', 100))[0])->toBe(413);

    // a connection that never finishes its head is answered 408 by the tick
    $slow = stream_socket_client("tcp://127.0.0.1:{$server->port()}", $errno, $error, 1);
    stream_set_blocking($slow, false);
    fwrite($slow, "POST /mcp HTTP/1.1\r\n");
    $in = '';
    $loop->until(function () use ($slow, &$in): bool { $in .= (string) fread($slow, 65536); return str_contains($in, '408'); });
    expect($in)->toContain('408');
});

it('serves our own http client end to end: handshake, JSON replies, an event-stream reply, a pushed notification, DELETE on close', function () {
    [$server, $loop, $token, $mcp] = serve();
    $this->server = $server;
    $client = new MCPClient('bench', $loop);
    $client->attach(new HttpConnection(['type' => 'http', 'url' => $server->url(), 'headers' => ['Authorization' => "Bearer {$token}"], 'fallback' => false], $loop, $client));

    $client->open();
    expect($client->ready()->wait())->toBe($client)
        ->and($client->protocolVersion())->toBe('2025-06-18')
        ->and($client->serverInfo()['name'])->toBe('bench-http')
        ->and(array_column($client->tools(), 'name'))->toBe(['rev', 'greet']);

    $client->ping()->wait();

    // tools/call runs in a fiber, so its reply comes down the POST as an event stream, progress first
    expect($client->callTool('rev', ['text' => 'abc'])->wait()->content[0]->value)->toBe('cba');

    // the list_changed the tool queued came down that same stream after the reply
    $seen = fn (): int => substr_count(json_encode(log_entries(), JSON_UNESCAPED_SLASHES), 'incoming notifications/tools/list_changed');
    $loop->until(function () use ($seen): bool { return $seen() >= 1; });

    // what the server says on its own reaches the listening GET stream
    $mcp->notify(new ToolListChangedNotification());
    $loop->until(function () use ($seen): bool { return $seen() >= 2; });
    expect($client->status())->toBe(ClientStatus::Connected);

    $client->close();
    $loop->until(function () use ($client): bool { return $client->closed(); });
    $loop->until(function () use ($server): bool { return $server->sessionCount() === 0; });
    expect($client->status())->toBe(ClientStatus::NotConnected)->and($server->sessionCount())->toBe(0);
});

it('resumes a listening stream from Last-Event-ID and drops an idle session', function () {
    [$server, $loop, $token, $mcp] = serve(['session' => ['idle' => 1.0]]);
    $this->server = $server;
    $auth = ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'];

    [$status, $headers, $body] = raw($server, $loop, 'POST', '/mcp', $auth, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'raw', 'version' => '1']]]));
    expect($status)->toBe(200)->and($headers)->toHaveKey('mcp-session-id')->and(json_decode($body, true)['result']['protocolVersion'])->toBe('2025-03-26');
    $session = ['Mcp-Session-Id' => $headers['mcp-session-id']];

    expect(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$session], '{"jsonrpc":"2.0","method":"notifications/initialized"}')[0])->toBe(202);

    // two notifications queued while nobody listens: the tick pushes them into the ring as events 1 and 2
    $mcp->notify(new ToolListChangedNotification());
    $mcp->notify(new ToolListChangedNotification());
    $tick = microtime(true) + 0.25;
    $loop->until(function () use ($tick): bool { return microtime(true) > $tick; });

    $socket = stream_socket_client("tcp://127.0.0.1:{$server->port()}", $errno, $error, 1);
    stream_set_blocking($socket, false);
    fwrite($socket, "GET /mcp HTTP/1.1\r\nHost: x\r\nAccept: text/event-stream\r\nAuthorization: Bearer {$token}\r\nMcp-Session-Id: {$session['Mcp-Session-Id']}\r\nLast-Event-ID: 1\r\n\r\n");
    $in = '';
    $loop->until(function () use ($socket, &$in): bool { $in .= (string) fread($socket, 65536); return str_contains($in, "data:"); });
    fclose($socket);

    expect($in)->toContain('text/event-stream')->toContain("id: 2\n")->not->toContain("id: 1\n");

    // idle longer than web.session.idle: the session is gone
    $loop->until(function () use ($server): bool { return $server->sessionCount() === 0; });
    expect(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$session], '{"jsonrpc":"2.0","id":2,"method":"ping"}')[0])->toBe(404);
});

it('asks for no bearer when started without a token, and still refuses a foreign origin', function () {
    $loop = testLoop();
    $app = bareApp([], $loop);
    $server = new McpHttpServer($loop, new ServerRepository($app));
    $this->server = $server->start(new BenchHttpServer(), 0, null);
    $init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'raw', 'version' => '1']]]);

    expect(raw($server, $loop, 'POST', '/mcp', ['Content-Type' => 'application/json'], $init)[0])->toBe(200)
        ->and(raw($server, $loop, 'POST', '/mcp', ['Content-Type' => 'application/json', 'Origin' => 'https://evil.example'], $init)[0])->toBe(403);

    $server->stop();
    expect(fn () => $server->start(new BenchHttpServer(), 0, ''))->toThrow(RuntimeException::class, 'pass null');
});

it('asks the user over the POST stream and takes the answer as its own POST', function () {
    [$server, $loop, $token] = serve();
    $this->server = $server;
    $asked = [];
    $client = (new MCPClient('bench', $loop))->onElicit(function ($request) use (&$asked): ElicitAnswer {
        $asked[] = $request->message;

        return ElicitAnswer::accept(['name' => 'Ada']);
    });
    $client->attach(new HttpConnection(['type' => 'http', 'url' => $server->url(), 'headers' => ['Authorization' => "Bearer {$token}"], 'fallback' => false], $loop, $client));

    $client->open();
    $client->ready()->wait();

    expect($client->callTool('greet')->wait()->content[0]->value)->toBe('hello Ada')->and($asked)->toBe(['Name?']);
});

it('holds a 2025-06-18 session to its version header and refuses its batches; an older session keeps batches', function () {
    [$server, $loop, $token] = serve();
    $this->server = $server;
    $auth = ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'];
    $init = fn (string $version): string => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'raw', 'version' => '1']]]);
    $ping = '{"jsonrpc":"2.0","id":2,"method":"ping"}';

    $new = ['Mcp-Session-Id' => raw($server, $loop, 'POST', '/mcp', $auth, $init('2025-06-18'))[1]['mcp-session-id']];
    raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new, 'MCP-Protocol-Version' => '2025-06-18'], '{"jsonrpc":"2.0","method":"notifications/initialized"}');

    expect(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new, 'MCP-Protocol-Version' => '2025-06-18'], $ping)[0])->toBe(200)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new], '{"jsonrpc":"2.0","id":3,"method":"ping"}')[0])->toBe(200)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new, 'MCP-Protocol-Version' => '2025-03-26'], '{"jsonrpc":"2.0","id":4,"method":"ping"}')[0])->toBe(400)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new, 'MCP-Protocol-Version' => '1999-01-01'], '{"jsonrpc":"2.0","id":5,"method":"ping"}')[0])->toBe(400)
        ->and(raw($server, $loop, 'POST', '/mcp', [...$auth, ...$new], '[{"jsonrpc":"2.0","id":6,"method":"ping"}]')[0])->toBe(400);

    $old = ['Mcp-Session-Id' => raw($server, $loop, 'POST', '/mcp', $auth, $init('2025-03-26'))[1]['mcp-session-id']];
    raw($server, $loop, 'POST', '/mcp', [...$auth, ...$old], '{"jsonrpc":"2.0","method":"notifications/initialized"}');
    [$status, , $body] = raw($server, $loop, 'POST', '/mcp', [...$auth, ...$old], '[{"jsonrpc":"2.0","id":7,"method":"ping"},{"jsonrpc":"2.0","id":8,"method":"ping"}]');

    expect($status)->toBe(200)->and(array_column(json_decode($body, true), 'id'))->toBe([7, 8]);
});
