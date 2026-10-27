# Agent guidance — semiconductor/sse

1. **Read [`.okf/index.md`](.okf/index.md) first** before changing a connection or the server.
2. **This is a transport, not the protocol.** Messages, procedures, ids, capabilities and errors belong to superconductor/mcp. A protocol change goes upstream first.
3. **Two transports, one spec page each.** `HttpConnection` is Streamable HTTP (2025-03-26 and 2025-06-18): one endpoint, a POST per message answered with JSON or an event stream, `Mcp-Session-Id`, `MCP-Protocol-Version` from 2025-06-18, a listening GET, DELETE on close. `SseConnection` is 2024-11-05 HTTP with SSE, deprecated, kept as the fallback. Check the spec page before changing either. See [`.okf/client/http.md`](.okf/client/http.md) and [`.okf/client/connection.md`](.okf/client/connection.md).
4. **The connection never blocks.** Each connection is one `WakeSource` over `Http\Exchange` sockets, read and written on wakes only. No `stream_select` loops, no blocking reads, no HTTP client that does its own waiting. HTTP and SSE parsing live in `src/Http` once, for both.
5. **Decoded events go straight to the client**, never to loop mail: two servers can both answer id 1.
6. **Credentials ride the entry's `headers`** on every request, and the TLS peer is always verified. No option switches either off.
7. **Failures name the server's answer**: the status of a refused stream or POST, the content type of a non-stream, the stream closing. The client sees them through `connectionClosed()`.
8. **Tests are Pest v4 and run the fixture servers** in `tests/Fixtures/http-server.php` and `sse-server.php` on `127.0.0.1`. A behaviour change comes with a case there, and the official SDK's `StreamableHTTPServerTransport` and `SSEServerTransport` stay the interop checks.
9. **Durable facts go in `.okf`.** Update the matching concept, bump its `generated.at`, append `.okf/log.md`.
10. **The server is local and opinionated, on purpose.** Loopback bind, one bearer, one endpoint, no TLS, no users, no OAuth, no CORS. An application switches it on from its settings; it is not a web server to deploy. Do not add routes, middleware or auth schemes without Angel's call. See [`.okf/server/http.md`](.okf/server/http.md).
11. **Versions** move with superconductor/mcp's, only past a published tag.
