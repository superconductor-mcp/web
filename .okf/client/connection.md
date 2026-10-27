---
type: Client
title: Connection
description: SseConnection: the GET stream, the endpoint event, one POST per message, SSE and chunked parsing, failures, close.
resource: src/Client/SseConnection.php
tags: [mcp, sse, client, http]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T15:30:00Z }
sources:
  - id: connection
    resource: src/Client/SseConnection.php
    title: SseConnection
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2024-11-05/basic/transports
    title: Transports, 2024-11-05
---

# Deprecated with 2024-11-05

This is the HTTP with SSE transport that 2025-03-26 replaced with Streamable HTTP ([http](http.md)). It stays for servers still on it, and `HttpConnection` falls back to it. Since 2025-03-26 it runs on the shared `Http\Exchange` and `Http\EventParser`; each event's data may be a batch.

# Entry

`['type' => 'sse', 'url' => 'http(s)://host[:port]/path', 'headers' => [...]]`. The provider registers the `sse` creator on `ClientManager`; the client is built and opened like any other.

# Stream

`open()` parses the url (http or https with a host, else "could not start"), opens an async socket (`tcp://` or `tls://` with `peer_name`), queues `GET <path>` with `Accept: text/event-stream`, `Host`, and the entry's headers, and joins the loop as `mcp.client.<name>`. The request leaves on the first `Writable` wake.

The response head is read first: not `200`, or a content type that is not `text/event-stream`, ends the connection naming the answer. `Transfer-Encoding: chunked` turns the dechunker on. From then on bytes are SSE: `event:` sets the type, `data:` lines accumulate, `:` lines are comments, a blank line dispatches. An `endpoint` event sets the POST target (absolute urls keep path and query; relative ones resolve against the stream's path) and releases messages that were waiting. Any other event's data is one JSON-RPC line through `LineDecoder`, batched and handed to `MCPClient::receive()`.

# POST

`send($line)` before the endpoint is known queues the line. After, each line is its own socket: async connect, `POST <endpoint>` with `Content-Type: application/json`, `Content-Length`, `Host` and the entry's headers; written on `Writable`, its response head read on `Readable`. A `2xx` closes the socket and forgets it; anything else, or the server closing the socket unanswered, ends the connection. The reply to the message arrives on the stream, not on the POST.

# Wakes

`wakes()`: `Readable` on the stream, `Writable` while its request is unsent, and per POST `Writable` while bytes wait else `Readable`. `woke()` matches each wake's `stream` to the stream socket or a POST socket.

# Ending

`close()` leaves the loop, closes every socket, clears what waited. `closed()` is true from then on. An EOF on the stream, a write failure, a refused stream or POST call `ended()`: close, then `MCPClient::connectionClosed($why)`, which fails the client while it is connecting or connected.

# Not in this release

The server end (`rocket mcp:sse <server>`), a config file, reconnection, redirects.
