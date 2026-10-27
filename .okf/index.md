---
okf_version: "0.2"
---

# semiconductor/sse

# Client

* [Streamable HTTP](client/http.md) - `HttpConnection` (2025-03-26): one endpoint, a POST per message answered with JSON or an event stream, the session id, the listening GET and its resume, DELETE on close, the 2024-11-05 fallback.
* [Connection](client/connection.md) - `SseConnection` (2024-11-05, deprecated): the GET stream, the endpoint event, one POST per message, SSE and chunked parsing, failures, close.

# Server

* [Local HTTP server](server/http.md) - `McpHttpServer`: an MCP server on a loopback port an application switches on; sessions, POST/GET/DELETE, bearer and Origin checks, the tick.

# Reference

* [Log](log.md) - Changes that altered behaviour.
