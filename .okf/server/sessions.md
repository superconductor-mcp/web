---
type: Server
title: Sessions
description: HttpSessions and HttpSession: opened on initialize, found by id and server, kept alive by streams and requests in flight, forgotten on DELETE or idle.
resource: src/Sessions/HttpSessions.php
tags: [mcp, http, session, lifecycle]
status: draft
generated: { by: claude-sonnet-5-5/claude-code, at: 2026-10-29T01:00:00Z }
sources:
  - id: sessions
    resource: src/Sessions/HttpSessions.php
    title: HttpSessions
  - id: session
    resource: src/Sessions/HttpSession.php
    title: HttpSession
  - id: tests
    resource: tests/Http/ExpiryTest.php
    title: Idle sessions are swept, one with an open stream is not
  - id: stream-tests
    resource: tests/Http/StreamsTest.php
    title: The GET stream, a hang-up and DELETE
---

# One session, one server instance

`HttpSessions` is the open sessions by id, a singleton in the container. `open($server)` makes a session on a new instance of the named server from core's `ServerRepository` (an unknown name throws there), copies `sse.notifications` onto its `SessionBag` and wraps a core `Mechanism\Session` on the application's loop. The id is `bin2hex(random_bytes(16))`: 32 hex characters.[^sessions]

`find($id, $server)` returns the session only when it was opened on that server, so an id used on another `{server}` is unknown. It also stamps the session as just seen.[^sessions]

`HttpSession` holds the id, the server name, the core session, `last_seen`, the count of GET streams open on it and a `closed` flag. `bindServer()` registers the session's own server instance in the container, under its class and `Contracts\Server\MCPServer`, before a request is handled, so a tool that asks for its server gets this session's.[^session]

# What keeps a session

`forget()` marks the session closed and drops it from the registry; DELETE calls it, and so does the idle sweep. A GET stream checks `closed` on every poll, so it ends on its next look.[^sessions][^stream-tests]

The sweep runs whenever the registry is used (a session is opened or looked up). A session is forgotten when it was last seen more than `sse.session.idle_timeout` seconds ago and it has no GET stream open and no request in flight (`SessionBag::in_flight`). A stream decrements the count and stamps `last_seen` when it ends, whether the session closed or the client hung up.[^sessions][^tests]

# Limits

Sessions live in the memory of the process that opened them. A second process serving the same application (venusian/serve workers) does not know them and answers `404`, so the client starts a new session. Nothing caps the number of sessions an allowed client may open.[^sessions]
