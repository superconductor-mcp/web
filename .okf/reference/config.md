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
| `controllers.metadata` | `ProtectedResourceController` | `routes/web.php`: the protected resource metadata route, added only when `authorization.guard` is set |
| `controllers.oauth_metadata` / `_authorize` / `_token` / `_register` / `_revoke` / `_login` | the `Http\Controllers\OAuth` classes | `routes/web.php`: the authorization server's routes, when it is enabled; registered as singletons |
| `middleware` | `host`, `auth`, `protocol`, `session` | the provider aliases each as `mcp.<key>`; `routes/web.php` runs them in this order on the three routes[^config-tests] |
| `allowed_hosts` | `localhost`, `127.0.0.1`, `[::1]` | `ValidateHost`: the hosts `Host` and `Origin` may name; `*` allows any |
| `authorization.guard` | `env('MCP_AUTH_GUARD')`, null (`mcp` when the server is enabled) | `Authenticate`, per request: the auth guard that checks the bearer token; null leaves the endpoint open. `routes/web.php`, at boot: adds the metadata route. `mcp` is the provider's guard over `mcp_access_tokens` |
| `authorization.servers` | `MCP_AUTHORIZATION_SERVERS`, comma-separated | `ProtectedResourceController`: `authorization_servers` |
| `authorization.scopes` | `[]` | `Authenticate`: scopes every token must carry, else `403 insufficient_scope`; `ProtectedResourceController` and `AuthorizationServer::metadata()`: `scopes_supported`; `AuthorizationServer`: the scopes a code can grant |
| `authorization.server.enabled` | `MCP_AUTHORIZATION_SERVER`, `false` | `routes/web.php`: the server's routes; `SSEServiceProvider`: guard defaults to `mcp`, boot fails for a guard without `mcp-token`; `ProtectedResourceController`: lists the issuer first |
| `authorization.server.prefix` | `oauth` | `routes/web.php`, `AuthorizationServer::endpoint()`, the provider's `cors.paths` |
| `authorization.server.session_guard` | `web` | `AuthorizeController`, `LoginController`: who is signed in to approve |
| `authorization.server.login` | `null` | `AuthorizationServer::loginUrl()`: a route name or path; null routes and uses the package's login page |
| `authorization.server.registration` | `true` | `routes/web.php` and the metadata's `registration_endpoint` |
| `authorization.server.client_metadata_documents` | `true` | `Clients::find()` / `authenticate()`; the metadata's `client_id_metadata_document_supported` |
| `authorization.server.code_ttl` / `access_ttl` / `refresh_ttl` | `60` / `3600` / `2592000` | `AuthorizationServer`: seconds each lives |
| `session.idle_timeout` | `1800` | `HttpSessions`: seconds before an idle session is forgotten |
| `session.poll_interval` | `0.1` | `StreamController`: seconds between two looks at the outbox on an open GET stream |
| `session.replay_events` | `100` | `HttpSession::openStream()`: events each stream keeps after writing them, for a resume with `Last-Event-ID` |
| `notifications` | `notifications/initialized` and `notifications/cancelled` to core's Action classes | `HttpSessions::open()`: copied onto each new session's bag |

The protocol versions a session speaks are core's `mcp.servers.supported_mcp_versions`, which `ValidateProtocolVersion` also reads. A key an application sets in its own `config/sse.php` replaces the package's value for that key as a whole, so a replaced `controllers` or `middleware` array lists every entry.

[^config-tests]: A controller, a path and a middleware list swapped through config
