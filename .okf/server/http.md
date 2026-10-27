---
type: Server
title: Local HTTP server
description: McpHttpServer: an MCP server on a loopback port for an application that switches it on; Incoming connections, HttpSession per Mcp-Session-Id, POST/GET/DELETE on /mcp, the bearer and Origin checks, the tick.
resource: src/Server/McpHttpServer.php
tags: [mcp, streamable-http, server, local]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T18:00:00Z }
sources:
  - id: server
    resource: src/Server/McpHttpServer.php
    title: McpHttpServer
  - id: session
    resource: src/Server/HttpSession.php
    title: HttpSession
  - id: incoming
    resource: src/Server/Incoming.php
    title: Incoming
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
    title: Transports, 2025-03-26
---

# What it is for

A compiled application (a CAD tool, a printer controller) turns on "MCP server" in its settings. Its loop is already running. `McpHttpServer::start($server, $port, $token)` joins that loop; `stop()` leaves it. A harness on the same machine connects to `http://127.0.0.1:<port>/mcp` with the token the app shows. One user, loopback only. It is not a web server for deployment: no TLS, no keep-alive, no files, no users, no OAuth.

`rocket mcp:http <server>` is the same object from the command line for development; it prints the url and the bearer on stderr.

# The resource

`McpHttpServer` is one `WakeSource`: `Readable` on the listening socket, and per `Incoming` connection `Readable` while reading or `Writable` while bytes wait. A tick timer every 100 ms does the housekeeping: 408 for a head that never completes, idle sessions dropped, finished streams closed, the server's outbox flushed to each session.

`Incoming` reads one request (head and sized body, limits from `web.limits`, chunked request bodies refused) and writes one response: a document (`respond()`), or an event stream (`stream()`, `event()`, `end()`). Every response says `Connection: close`.

# The endpoint

| Request | Answer |
|---|---|
| any path but `/mcp` | 404 |
| `Origin` not localhost | 403, before anything else is looked at |
| `Authorization` not `Bearer <token>` (constant-time compare) | 401 + `WWW-Authenticate: Bearer` |
| `Mcp-Session-Id` unknown | 404 |
| `POST` without a session, body not holding `initialize` | 400 |
| `POST` `initialize` | new `HttpSession`, random id, reply carries `Mcp-Session-Id` |
| `POST` requests, all sync | one JSON document: the reply, or an array of replies for a batch |
| `POST` with an `ASYNC` request | an event stream: progress and replies as events, closed once no request of that POST is in flight (the tick catches a cancelled one) |
| `POST` notifications or responses only | 202 |
| `GET` | the listening stream; events the session has not delivered, or those after `Last-Event-ID`, replayed from a ring of `web.session.replay` |
| `DELETE` | session gone, 200 |
| other methods | 405 |

# Where lines go

`HttpSession` gives `Session::handle()` a `$send` per POST. A document POST keeps replies for the document and pushes anything with a `method` (a notification the server queued) to the stream ring. A stream POST writes everything to its own stream. `push()` numbers each line, keeps the newest `replay`, and writes it to the live listener if there is one. One `MCPServer` instance serves every session, so its outbox is drained by whichever session the tick reaches first; for the one-harness use this package exists for, that is the one session.

# Config

`config/web.php`: `host`, `port`, `token`, `limits.head`, `limits.body`, `limits.head_timeout`, `session.idle`, `session.replay`, `notifications` (the session's action map).
