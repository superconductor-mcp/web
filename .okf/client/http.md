---
type: Client
title: Streamable HTTP
description: HttpConnection: one endpoint, a POST per message answered with JSON or an event stream, the session id, the listening GET and its resume, DELETE on close, the 2024-11-05 fallback.
resource: src/Client/HttpConnection.php
tags: [mcp, streamable-http, client, http, sse]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T15:30:00Z }
sources:
  - id: connection
    resource: src/Client/HttpConnection.php
    title: HttpConnection
  - id: exchange
    resource: src/Http/Exchange.php
    title: Exchange
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
    title: Transports, 2025-03-26
---

# Entry

`type => http`, `url` the MCP endpoint, `headers` on every request (credentials live here), `fallback` (default `true`) to try 2024-11-05 HTTP with SSE when the endpoint refuses the initialize POST. `open()` checks the url, joins the loop as `mcp.client.<name>`, and sends nothing: the client's first `send()` is the initialize.

# Every message is a POST

`send()` opens one `Exchange` (an async socket carrying one HTTP/1.1 request, `Connection: close`) with `Accept: application/json, text/event-stream`, `Content-Type: application/json` and, once known, `Mcp-Session-Id`. The response head decides the body:

| Answer | Then |
|---|---|
| `2xx` with `application/json` | the body, complete, is one message or a batch → `LineDecoder::decode()` → the client |
| `2xx` with `text/event-stream` | each event's `data` is a message or a batch, delivered as it arrives, until the server closes |
| `2xx` with nothing (202) | done |
| `4xx` to the initialize POST, with `fallback` on | hand the client to `SseConnection` on the same url, re-sending the initialize line; see below |
| `404` once a session id was given | "the server ended the session." → the client fails |
| any other non-2xx | "the server answered N to a posted message." → the client fails |

An `Mcp-Session-Id` on any response is kept from the first one seen and sent on every later request.

# The listening GET

Once the client is `Connected`, a GET with `Accept: text/event-stream` opens the stream the server may speak on. `405`, or anything that is not a `200` event stream, means the server offers none and it is not asked again. `404` with a session fails the client. Every `id:` on the stream is remembered; when the server drops the stream, a timer reopens it one second later with `Last-Event-ID`. A stream dropped `client.stream_reconnects` times in a row without delivering anything is given up.

# Close

`close()` drops every exchange, then sends `DELETE` with the session id when there is one and releases once that request has been answered or two seconds have passed; a server without sessions is simply left. `closed()` turns true at release, so `ClientManager::closeAll()` waits for the DELETE to leave.

# The fallback

The spec's compatibility path: a `4xx` to the first POST is taken as "this endpoint speaks 2024-11-05". The connection forgets its loop resource, opens an `SseConnection` on the same entry (which registers under the same name) and sends it the initialize line the POST carried. From then on `send()`, `close()` and `closed()` delegate; the `MCPClient` never learns which transport it ended up on. The `type => sse` creator stays for an entry that wants the old transport outright.

# Shared plumbing

`Http\Exchange` owns one socket: flushes the request, reads the head (status, headers, lowercased), dechunks, and says when the body is complete (`Content-Length` reached, the last chunk, or the peer closing). `Http\EventParser` turns body text into `[event, data]` pairs and keeps the last `id:`. `SseConnection` runs on the same two pieces; a change to HTTP or SSE parsing is made once.
