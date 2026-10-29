---
type: Reference
title: Config
description: Every key in config/sse.php and who reads it.
resource: config/sse.php
tags: [mcp, http, config]
status: draft
generated: { by: claude-sonnet-5-5/claude-code, at: 2026-10-29T01:00:00Z }
sources:
  - id: config
    resource: config/sse.php
    title: config/sse.php
  - id: config-tests
    resource: tests/Http/ConfigTest.php
    title: A controller, a path and a middleware list swapped through config
---

| Key | Default | Read by |
|---|---|---|
| `routes` | `null` | `SSEServiceProvider::boot()`: the routes file to load, else the package's `routes/web.php` |
| `path` | `mcp/{server}` | `routes/web.php`: the URI of all three routes[^config-tests] |
| `controllers.messages` / `.stream` / `.session` | `MessagesController`, `StreamController`, `SessionController` | `routes/web.php` for the route action; the provider registers each as a singleton[^config-tests] |
| `middleware` | `host`, `protocol`, `session` | the provider aliases each as `mcp.<key>`; `routes/web.php` runs them in this order on the three routes[^config-tests] |
| `allowed_hosts` | `localhost`, `127.0.0.1`, `[::1]` | `ValidateHost`: the hosts `Host` and `Origin` may name; `*` allows any |
| `session.idle_timeout` | `1800` | `HttpSessions`: seconds before an idle session is forgotten |
| `session.poll_interval` | `0.1` | `StreamController`: seconds between two looks at the outbox on an open GET stream |
| `notifications` | `notifications/initialized` and `notifications/cancelled` to core's Action classes | `HttpSessions::open()`: copied onto each new session's bag |

The protocol versions a session speaks are core's `mcp.servers.supported_mcp_versions`, which `ValidateProtocolVersion` also reads. A key an application sets in its own `config/sse.php` replaces the package's value for that key as a whole, so a replaced `controllers` or `middleware` array lists every entry.

[^config-tests]: A controller, a path and a middleware list swapped through config
