# Log

## 2026-10-27 (2025-06-18)

* **Client sends `MCP-Protocol-Version`** on every request after a 2025-06-18 handshake ([http](client/http.md)).
* **Server checks it**: a header naming a version it does not speak, or not the session's, is a 400. Absent means the session's version ([server](server/http.md)).
* **Batches by version on POST**: a 2025-06-18 session's batch is a 400; older sessions keep them.
* **Elicitation over HTTP**: the question goes down the asking POST's event stream; the client's answer is its own POST, answered 202.
* Pest suite: 19 tests, 81 assertions. Proven with the TypeScript SDK 1.22.0 client (2025-06-18, elicitation handler).

## 2026-10-27

* **Bearer is optional**: `start()` takes `?string $token`, null = no check; `--no-auth`; `web.auth`. Empty string still throws. Origin check unchanged ([http](server/http.md)).

## 2026-10-26 (2025-03-26)

* **A POST stream closes after the outbox flush**, never inside the send: a `list_changed` a tool queues reaches the client on that stream. Found by the inspector session's e2e ([http](server/http.md)).
* **Server end**: `McpHttpServer` with `start()`/`stop()` for app code and `rocket mcp:http <server>`; `Incoming`, `HttpSession`, `config/web.php` ([http](server/http.md)). Runs superconductor/mcp's `Mechanism\Session`. Proven by the package's own `HttpConnection`, the TypeScript SDK's `StreamableHTTPClientTransport` 1.22.0 (handshake, tools, streamed progress, DELETE) and the dev app's `mcp:client` sketch.
* **Streamable HTTP client**: `HttpConnection`, `type => http` ([http](client/http.md)). Session id, JSON or event-stream replies, listening GET with `Last-Event-ID` resume, DELETE on close, fallback to the old transport on a `4xx` initialize.
* **Shared plumbing**: `Http\Exchange` and `Http\EventParser`; `SseConnection` rebuilt on them, behaviour unchanged ([connection](client/connection.md)).
* `type => sse` deprecated with 2024-11-05, still served.
* Pest suite: 13 tests, 41 assertions. Proven against the TypeScript SDK's `StreamableHTTPServerTransport` (1.22.0) and a production Streamable HTTP server behind a bearer token.

## 2026-10-26

* **Client end**: `SseConnection` and the `sse` creator on `mcp.clients` ([connection](client/connection.md)). Proven against the fixture server (plain and chunked) and the official TypeScript SDK's `SSEServerTransport` 1.22.0: handshake, lists, ping 1.3 ms, a tool call, close. Pest suite: 6 tests, 15 assertions. No config file and no server end yet.
