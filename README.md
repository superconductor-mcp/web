# semiconductor/sse

[![Tests](https://github.com/superconductor-mcp/sse/actions/workflows/tests.yml/badge.svg)](https://github.com/superconductor-mcp/sse/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/semiconductor/sse.svg)](https://packagist.org/packages/semiconductor/sse)
[![License](https://img.shields.io/packagist/l/semiconductor/sse.svg)](LICENSE)

Connect a Venusian app to MCP servers over HTTP: Streamable HTTP (2025-03-26, 2025-06-18) with the older HTTP with SSE (2024-11-05) as a fallback, both on the app's own event loop.

`semiconductor/sse` is the HTTP transport for [superconductor/mcp](https://github.com/superconductor-mcp/mcp). An `mcp_servers` entry of type `http` becomes an `MCPClient` on the Streamable HTTP transport; type `sse` is the 2024-11-05 transport, deprecated with that protocol version and kept for servers still on it. The server end is `McpHttpServer`: an MCP server on a loopback port that an application switches on from its settings, so a compiled app that cannot speak stdio can still be reached by a harness on the same machine.

```
superconductor/mcp          messages, procedures, MCPServer, MCPClient, the client manager
  → semiconductor/stdio     the stdio transport: serve as a child process, consume one
  → semiconductor/sse       the HTTP transports: consume a server over Streamable HTTP or SSE, serve one on localhost   ← this package
```

## Requirements

- PHP 8.4 or newer
- A Venusian 0.10 application (`venusian-voyager/io-pools` 0.10)
- `superconductor/mcp` 0.10

## Installation

```bash
composer require superconductor/mcp semiconductor/sse
```

The service provider is discovered automatically. It registers the `http` and `sse` creators on the client manager, `mcp.clients`, binds `McpHttpServer` as a singleton, adds `mcp:http` to the sketches, and merges `config/web.php`. Publish it with `php computer vendor:publish --tag=web-config`.

## Connecting

Name the server in `config/mcp.php`. `url` is the MCP endpoint; `headers` go on every request the connection makes, which is where a bearer token or any other credential header goes. `fallback => false` turns off the try of the old transport when the endpoint answers `4xx` to initialize.

```php
'mcp_servers' => [
    'remote' => [
        'type' => 'http',
        'url' => 'https://mcp.example.com/mcp',
        'headers' => ['Authorization' => 'Bearer '.env('REMOTE_MCP_TOKEN')],
    ],
],
```

```php
$remote = app(ClientManager::class)->client('remote');
$remote->ready()->wait();                       // handshake done, session kept, lists cached
$remote->tools();
$remote->callTool('add', ['a' => 2, 'b' => 3])->wait()->content;
```

Everything on `MCPClient` works the same as over stdio: promises for every call, caches that refill on `list_changed`, server pings answered, `close()` and `closeAll()`.

## How the connection works

Both connections are loop `WakeSource`s over plain sockets, `http` or `https`, sharing one HTTP exchange and one SSE parser.

**Streamable HTTP**, `HttpConnection`:

- Every message the client sends is its own `POST` to the endpoint with `Accept: application/json, text/event-stream`. A JSON body is delivered once complete; an event stream is delivered event by event; a `202` is done. Below 2025-06-18 either may carry a JSON-RPC batch.
- After a 2025-06-18 handshake every request carries `MCP-Protocol-Version`.
- The `Mcp-Session-Id` the server gives at initialize rides on every later request. A `404` after that fails the client with "the server ended the session."
- Once connected, a `GET` opens the stream the server may speak on; `405` means it offers none. When the server drops it, it is reopened with `Last-Event-ID` so nothing is lost, up to `client.stream_reconnects` silent drops in a row.
- `close()` sends `DELETE` with the session id, waits for the answer or two seconds, then releases.
- A `4xx` to the initialize POST hands the client to the old transport on the same url, as the spec's compatibility path says.

**HTTP with SSE**, `SseConnection`, deprecated:

- `open()` connects asynchronously and sends `GET <url>` with `Accept: text/event-stream`. Anything but `200` with that content type fails the client with the server's answer.
- The server's first event is `endpoint`, the URI to POST to, absolute or relative to the stream's url. Messages sent before it arrives wait for it.
- Each message the client sends is its own `POST` with `Content-Type: application/json`. A `2xx` is all the server owes; its reply comes down the stream as a `message` event. Anything else fails the client.
- The stream is read on wakes, dechunked when the server uses chunked transfer encoding, and parsed as SSE: `event:` and `data:` lines, a blank line dispatches. Each `message` event is one JSON-RPC line, decoded and handed straight to the connection's own client, never to loop mail.
- `close()` drops the stream and any POST in flight; the server sees the stream end. A stream the server closes fails the client with "the server closed the event stream."

## Serving

The server is for one use: an application running on a desk turns on an MCP port so a harness on the same machine can drive it. From app code, on the loop that is already running:

```php
$server = app(McpHttpServer::class);
$token = McpHttpServer::token();          // show this in the settings screen
$server->start('cad', port: 8431, token: $token);
// ... the settings toggle goes off:
$server->stop();
```

From the command line, for development:

```bash
php rocket mcp:http concept --port=8431 --token=devtoken
```

In a harness, the entry is the url and the bearer:

```json
{ "mcpServers": { "cad": { "type": "http", "url": "http://127.0.0.1:8431/mcp", "headers": { "Authorization": "Bearer devtoken" } } } }
```

What it does: binds `127.0.0.1` only; refuses any `Origin` that is not localhost (403) and any request without the bearer (401); mints an `Mcp-Session-Id` at `initialize` and keeps one session per id; answers a POST with JSON, or with an event stream when a procedure runs async (progress first, then the reply); keeps a listening GET stream per session with `Last-Event-ID` resume; ends a session on DELETE or after `session.idle` seconds. Every response closes its connection. No TLS, no users, no OAuth: the token is the lock, and the port never leaves the machine.

`config/web.php`:

| Key | Default | What it does |
|---|---|---|
| `host`, `port` | `127.0.0.1`, `8431` | Where `mcp:http` listens. `MCP_HTTP_PORT` in `.env`. |
| `token` | `MCP_HTTP_TOKEN` | The bearer; empty lets `mcp:http` mint one and print it. |
| `auth` | `MCP_HTTP_AUTH` | `false` asks for no bearer. Same as `--no-auth`, or `start(..., token: null)` from code. Origin check and loopback binding stay. |
| `limits.head`, `limits.body`, `limits.head_timeout` | 16 KiB, 1 MiB, 10 s | Request limits: 400, 413, 408. |
| `session.idle`, `session.replay` | 300, 100 | Seconds before an idle session is dropped; events kept for a resume. |
| `notifications` | `initialized`, `cancelled` | The session's notification action map. |

## Testing

```bash
composer install
vendor/bin/pest
```

The suite runs two fixture servers under the PHP that runs the tests, `tests/Fixtures/http-server.php` (JSON and event-stream replies, session ids, the listening stream and its resume, `DELETE`, `401`, an expired session) and `sse-server.php` (plain and chunked streams, `401`, a `text/plain` answer, the fallback target). No network beyond `127.0.0.1`.

```console
Tests:    19 passed (81 assertions)
```

The server suite runs `McpHttpServer` on a free port: raw-socket refusals (404, 405, 401, 403, 400, 413, 408), the package's own `HttpConnection` end to end, and a `Last-Event-ID` resume. Both clients are also proven against the official TypeScript SDK (1.22.0), `StreamableHTTPServerTransport` and `SSEServerTransport`, and the server against its `StreamableHTTPClientTransport`: handshake, `tools/list`, `ping`, tool calls with streamed progress, session termination. The Streamable HTTP client has run against a production server behind a bearer token.

## License

MIT. See [LICENSE](LICENSE).
