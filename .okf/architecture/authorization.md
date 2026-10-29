---
type: Architecture
title: Authorization
description: The endpoint as an OAuth protected resource, and the built-in OAuth 2.1 authorization server that issues its tokens; published migrations, the guard, sessions owned by their opener.
resource: src/Auth
tags: [mcp, http, oauth, authorization]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: 2026-10-29T14:00:00Z }
sources:
  - id: authenticate
    resource: src/Http/Middleware/Authenticate.php
    title: Authenticate
  - id: metadata
    resource: src/Http/Controllers/ProtectedResourceController.php
    title: ProtectedResourceController
  - id: resource
    resource: src/Auth/ProtectedResource.php
    title: ProtectedResource
  - id: token
    resource: src/Auth/AccessToken.php
    title: AccessToken
  - id: server
    resource: src/Auth/Server/AuthorizationServer.php
    title: AuthorizationServer
  - id: clients
    resource: src/Auth/Server/Clients.php
    title: Clients
  - id: authorize
    resource: src/Http/Controllers/OAuth/AuthorizeController.php
    title: AuthorizeController
  - id: login
    resource: src/Http/Controllers/OAuth/LoginController.php
    title: LoginController
  - id: provider
    resource: src/Providers/SSEServiceProvider.php
    title: SSEServiceProvider
  - id: migrations
    resource: database/migrations
    title: create_mcp_access_tokens_table and create_mcp_oauth_tables, undated
  - id: tests
    resource: tests/Http/AuthorizationTest.php
    title: Challenges, metadata, scopes, token checks, session ownership, publishing
  - id: server-tests
    resource: tests/OAuth/AuthorizationServerTest.php
    title: The authorization server's flow, through the HTTP kernel
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
    title: Authorization (2025-11-25)
---

# Two halves

The endpoint is an OAuth 2.1 protected resource whenever `sse.authorization.guard` is set. The built-in authorization server (`sse.authorization.server.enabled`) issues the tokens that guard accepts; without it, tokens come from `sse.authorization.servers` or from the app through `AccessToken::issue()`.[^spec] With neither set the endpoint is open.

The provider fails at boot rather than serve something wrong: a guard with no `Authenticate` in `sse.middleware` (a `config/sse.php` published before it existed), and an enabled server whose guard does not use the `mcp-token` driver (it would refuse every token the server issues). Enabling the server with no guard makes the guard `mcp`.[^provider]

The `mcp` guard is defined in `boot()`, not `register()`: setting `auth.guards.mcp` while serve's provider has yet to merge `config/auth.php` would leave the app with only that guard, as package discovery's order did in the sandbox app.[^tests]

# The protected resource

`Authenticate` (`mcp.auth`, after the host check) asks the guard for a user.[^authenticate]

* No user: `401` with `WWW-Authenticate: Bearer resource_metadata="<base>/.well-known/oauth-protected-resource/<path>"`, plus `scope="…"` when `sse.authorization.scopes` lists any, plus `error="invalid_token"` when a bearer token was sent.
* A token (the `mcp.token` attribute, a `BearerToken`) lacking a listed scope: `403` with `error="insufficient_scope"`. A guard that sets no `mcp.token` passes only when no scopes are listed.
* Otherwise the user goes on the request as `mcp.user`.[^tests]

`GET /.well-known/oauth-protected-resource/<sse.path>` (RFC 9728 path insertion, outside the endpoint's middleware) answers `resource`, `authorization_servers` (this app's issuer first when the server is on, then `sse.authorization.servers`), `bearer_methods_supported` and `scopes_supported`.[^metadata] `ProtectedResource::uri($server, $base)` is the canonical URI: scheme and host lower-cased, the endpoint path with `{server}` filled in, no trailing slash.[^resource]

`AccessToken::authenticate()` accepts a token only if its SHA-256 hash is known, it has not expired, and its `resource` is this endpoint's canonical URI under the request's scheme and host (RFC 8707). It records `last_used_at` and sets `mcp.token`.[^token]

Sessions belong to their opener: `MessagesController` stores the opener's identifier on the `HttpSession`; `ResolveSession` answers `404`, as for an unknown id, to anyone else.[^tests]

# The authorization server

Issuer: the request's scheme and host. `GET /.well-known/oauth-authorization-server` (RFC 8414) lists the endpoints under `sse.authorization.server.prefix`, `code_challenge_methods_supported: ["S256"]`, `client_id_metadata_document_supported`, and `authorization_response_iss_parameter_supported`.[^server]

**Clients.** A `client_id` that is an https URL with a path is a client metadata document: fetched at the authorization request from a public address only (every A/AAAA record checked, the connection pinned to the checked address), without redirects, cut off past 5 KiB; it must name itself, list valid `redirect_uris` and ask for no client authentication. Any other `client_id` is one registered at `POST <prefix>/register` (RFC 7591, throttled 10/min): public (`none`) or confidential (a secret, stored hashed, sent once).[^clients]

**Authorize.** `GET <prefix>/authorize` (the `web` group: session, CSRF) checks the client and its redirect URI first, and shows an error page if either is wrong, never redirecting. Every later problem redirects with `error`, `state` and `iss`: `response_type` other than `code`, no `S256` challenge, a `resource` that is not one of this host's MCP endpoints (one may be left out when only one server is registered). Unknown scopes are dropped; none known asked for grants them all. A person who is not signed in to `session_guard` is sent to the login page with the request as the intended URL. The consent page shows the client's name (unverified for a registered client, the host for a metadata document), the redirect host, the resource and the scopes; it is not frameable. The request waits in the session (the newest ten) until `POST <prefix>/authorize` answers it once.[^authorize]

**Codes and tokens.** An approved code (SHA-256 stored, `code_ttl`) belongs to one client, redirect URI and PKCE challenge. `POST <prefix>/token` (throttled 60/min) authenticates the client (HTTP Basic, body secret, or a public `client_id`), then: the code must be unused, unexpired, the client's, with the same `redirect_uri` and a verifier matching the challenge; a `resource` given must match the grant. A code used twice revokes its grant. The response is an access token (`access_ttl`), and a refresh token (`refresh_ttl`) when the client may refresh. Each refresh revokes the token used and issues new ones; a revoked refresh token presented again revokes the grant. A refresh may narrow scopes, never widen them. Expired codes and tokens are pruned at each issue.[^server]

**Revoke.** `POST <prefix>/revoke` (RFC 7009): a refresh token ends its grant, an access token itself; another client's or an unknown token is ignored; always `200`.[^server]

**Login.** With `sse.authorization.server.login` null, `GET/POST <prefix>/login` is an email and password form for `session_guard` (5 tries a minute), which regenerates the session and goes to the intended URL. Otherwise the person goes to the app's login route or path, which must do the same.[^login]

Browser clients may call the metadata, token, registration and revocation endpoints across origins: the provider adds their paths to `cors.paths`.[^provider]

# Tables

The migrations are undated in the package and never run from it. `vendor:publish --tag=sse-migrations` copies them into the app's `database/migrations` with a date, tokens first, reusing a copy already there so a second publish adds nothing.[^migrations]

* `mcp_access_tokens`: `user_id`, `client_id` and `grant_id` (set for the server's tokens), `name`, `token` (hash), `abilities`, `resource`, `last_used_at`, `expires_at`.
* `mcp_oauth_clients`: registered clients; id, name, hashed secret, redirect URIs, grant types.
* `mcp_authorization_codes`: hashed code, grant, client, user, redirect URI, challenge, scopes, resource, `refreshable`, `used_at`, `expires_at`.
* `mcp_refresh_tokens`: hashed token, grant, client, user, scopes, resource, `revoked_at`, `expires_at`.

# Proven

The TypeScript SDK 1.32.2's own OAuth client against `php computer serve` in the sandbox app: 401, discovery of both metadata documents, registration, login and consent through a scripted browser with real cookies and CSRF, the code with PKCE, tool calls, then a refresh with rotation and no browser. That run was by hand, outside the suite; the suite covers the same flow through the HTTP kernel.[^server-tests]

[^authenticate]: Authenticate
[^metadata]: ProtectedResourceController
[^resource]: ProtectedResource
[^token]: AccessToken
[^server]: AuthorizationServer
[^clients]: Clients
[^authorize]: AuthorizeController
[^login]: LoginController
[^provider]: SSEServiceProvider
[^migrations]: create_mcp_access_tokens_table and create_mcp_oauth_tables, undated
[^tests]: Challenges, metadata, scopes, token checks, session ownership, publishing
[^server-tests]: The authorization server's flow, through the HTTP kernel
[^spec]: Authorization (2025-11-25)
