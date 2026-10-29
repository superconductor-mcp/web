---
type: Architecture
title: Transport
description: What this package owns (the routes, three controllers, the guards, the event stream) and what it leaves to superconductor/core and venusian/serve.
resource: src
tags: [mcp, http, sse, transport]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: 2026-10-29T02:00:00Z }
sources:
  - id: provider
    resource: src/Providers/SSEServiceProvider.php
    title: SSEServiceProvider
  - id: routes
    resource: routes/web.php
    title: The routes
  - id: messages
    resource: src/Http/Controllers/MessagesController.php
    title: MessagesController
  - id: stream
    resource: src/Http/Controllers/StreamController.php
    title: StreamController
  - id: session
    resource: src/Http/Controllers/SessionController.php
    title: SessionController
  - id: host
    resource: src/Http/Middleware/ValidateHost.php
    title: ValidateHost
  - id: protocol
    resource: src/Http/Middleware/ValidateProtocolVersion.php
    title: ValidateProtocolVersion
  - id: resolve
    resource: src/Http/Middleware/ResolveSession.php
    title: ResolveSession
  - id: events
    resource: src/Http/EventStream.php
    title: EventStream
  - id: resume-tests
    resource: tests/Http/ResumeTest.php
    title: Event ids, priming and resuming a stream
  - id: spec-resume
    resource: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#resumability-and-redelivery
    title: Streamable HTTP, resumability and redelivery (2025-11-25)
  - id: tests
    resource: tests/Http/SessionsTest.php
    title: The endpoint end to end over raw sockets
  - id: elicitation-tests
    resource: tests/Http/ElicitationTest.php
    title: A question on the POST stream, the answer in a second POST
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
    title: Transports, 2025-06-18
---

# Messages, not protocol

MCP's Streamable HTTP transport is one URL: POST for the client's messages, GET for the server's stream, DELETE to end the session.[^spec] This package puts those three methods on the application's router at `sse.path` (`mcp/{server}`, `{server}` the name of a registered `MCPServer`) and moves messages over them. What a message means is superconductor/core's: the controllers hand each decoded message to `Mechanism\Session::handle()` and write what its closure gives them.[^routes][^messages]

# The provider

`SSEServiceProvider` merges `config/sse.php`, registers `HttpSessions` and every configured controller and middleware class as singletons, aliases each middleware on the router as `mcp.<key>`, loads the routes file (`sse.routes`, else the package's own) and publishes the config as `sse-config`.[^provider] The routes file reads the path, the controller per method and the middleware list from config, so an application swaps any of them through `config/sse.php` alone.[^routes]

# The guards

The endpoint's middleware run in the order `sse.middleware` lists them:

1. `ValidateHost`: the `Host` header, and `Origin` when one is sent, must each name a host in `sse.allowed_hosts` (ports ignored, case folded, an IPv6 literal keeps its brackets); `*` allows any. Otherwise `403`. This is the DNS-rebinding defence the transport specification asks for.[^host]
2. `Authenticate`: with `sse.authorization.guard` set, a bearer token that guard accepts, else `401` (or `403` for a missing scope); see [Authorization](authorization.md). Without a guard it passes.
3. `ValidateProtocolVersion`: an `MCP-Protocol-Version` header that is not in `mcp.servers.supported_mcp_versions` is `400`; no header passes.[^protocol]
4. `ResolveSession`: the `Mcp-Session-Id` header names a session, which goes on the request as the `mcp.session` attribute. No header is `400` on GET and DELETE and passes on POST, where the controller requires it for anything but `initialize`. An id that is unknown, belongs to another server, or (with authorization on) was opened by another user, is `404`.[^resolve]

A guard answers with a status and a plain-text reason, not a JSON-RPC error.

# POST

`MessagesController` decodes the body with core's `LineDecoder`. It answers `406` when the body holds requests and `Accept` does not allow `text/event-stream`, before anything is handled. With no session it accepts only a single `initialize` for a registered server (`400` otherwise, `404` for an unknown server) and opens a session; with a session it refuses a batch the session does not take (`400`).[^messages]

Every message goes to the session. A body with only notifications or responses is answered `202` with no body (or `400` with the decoder's error when handling produced one).[^tests] A body with requests is answered `200` with `Content-Type: text/event-stream`, plus `Mcp-Session-Id` when this request opened the session. The stream carries each response and everything the server sends while the requests run (progress, log messages, elicitation) and ends once every request id in the body has left the session's in-flight set, answered or cancelled. A refused initialize leaves no session behind.[^messages][^events]

A tool that waits for the user (elicitation) holds its POST open while the answer arrives as a second POST, so the server that serves the application must run more than one request at a time: `serve.max_in_flight` is 10 by default.[^elicitation-tests]

# GET

`StreamController` answers `406` without `Accept: text/event-stream`. Otherwise it opens the session's listening stream for what the server sends outside any request: while a connection follows it, every `sse.session.poll_interval` seconds the session's outbox moves into it, and it runs until the session is closed. With no connection following, the outbox is left alone, so what the server queues meanwhile waits there rather than in a stream nobody reads.[^stream][^resume-tests]

With a `Last-Event-ID` that names one of the session's streams, it resumes that stream instead, a GET's or a POST's, from after that event; an id naming no stream of the session opens a new listening stream. If the client hangs up, venusian/serve cancels the request, the stream stops counting against the session, and the stream and session stay for the client to come back to.[^stream][^resume-tests]

# DELETE

`SessionController` forgets the session and answers `204`; its open streams end on their next look.[^session]

# Events and resuming

`EventStream` is one stream of a session, registered on the `HttpSession` until a connection follows it to its end. Each JSON line pushed onto it gets the next number on that stream and goes out as `id: <stream>:<seq>`, `event: message`, `data: <json>`, so ids are unique across the session's streams and name the stream they belong to. A 2025-11-25 session's stream opens with `id: <stream>:0` and empty data, so a client can resume a stream before it has carried anything; an older session's is not primed, since its client may not parse an empty event.[^events][^spec-resume]

A dropped connection is not a cancellation: the POST's requests run on, and what they send waits on their stream. A GET with `Last-Event-ID: <stream>:<n>` writes that stream's events after `n`, never another stream's, then follows it as the first connection would have; a POST stream ends once its requests are answered, and is then let go. A stream keeps the last `sse.session.replay_events` events it wrote (100) for such a replay, and every event it has not written yet. The server never closes a stream to make the client poll.[^events][^resume-tests][^spec-resume]

[^provider]: SSEServiceProvider
[^routes]: The routes
[^messages]: MessagesController
[^stream]: StreamController
[^session]: SessionController
[^host]: ValidateHost
[^protocol]: ValidateProtocolVersion
[^resolve]: ResolveSession
[^events]: EventStream
[^elicitation-tests]: A question on the POST stream, the answer in a second POST
[^tests]: The endpoint end to end over raw sockets
[^spec]: Transports, 2025-06-18
[^resume-tests]: Event ids, priming and resuming a stream
[^spec-resume]: Streamable HTTP, resumability and redelivery (2025-11-25)
