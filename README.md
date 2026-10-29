<div align="center" id="header">

<h1>superconductor/web</h1>
<h3>The Streamable HTTP transport for Superconductor MCP: serve your MCP servers as routes of your own application</h3>

[![CI](https://img.shields.io/github/actions/workflow/status/superconductor-mcp/web/tests.yml?style=flat-square&label=CI)](https://github.com/superconductor-mcp/web/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/superconductor/web?style=flat-square)](https://packagist.org/packages/superconductor/web)
[![PHP](https://img.shields.io/packagist/dependency-v/superconductor/web/php?style=flat-square&label=PHP)](https://packagist.org/packages/superconductor/web)
[![MCP](https://img.shields.io/badge/MCP-2025--11--25-5b4ee6?style=flat-square)](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
[![License](https://img.shields.io/github/license/superconductor-mcp/web?style=flat-square)](LICENSE)
[![OKF](https://img.shields.io/badge/OKF-0.2-6f42c1?style=flat-square&label=knowledge%20bundle)](.okf/index.md)

</div>

MCP's Streamable HTTP transport puts a server behind one URL: the client POSTs JSON-RPC messages to it and reads the answers as server-sent events, opens a GET stream for what the server says on its own, and ends its session with a DELETE. Remote harnesses speak it, and so does anything that can make an HTTP request.

[superconductor/core](https://github.com/superconductor-mcp/core) speaks the protocol but knows no transport by name. <b>The only problem?</b> Someone has to own the routes.

superconductor/web owns them. It adds `POST`, `GET` and `DELETE /mcp/{server}` to the application's own router, so a server you wrote for [superconductor/stdio](https://github.com/superconductor-mcp/stdio) is served over HTTP by the application that already serves your web routes, on the same event loop, with no second process. It serves; it has no HTTP client.

## Basic usage

Most ways you will interface with superconductor/web are in two parts -

**Serving** a server defined with superconductor/core. Every `#[Server]` class is at `/mcp/{name}`:

```bash
php computer serve            # venusian/serve: http://127.0.0.1:8020/mcp/concept
```

```console
$ curl -si http://127.0.0.1:8020/mcp/concept \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"me","version":"1"}}}'
HTTP/1.1 200 OK
Content-Type: text/event-stream; charset=utf-8
Mcp-Session-Id: 6f1c…

event: message
data: {"jsonrpc":"2.0","id":0,"result":{"protocolVersion":"2025-06-18","capabilities":{…},"serverInfo":{…}}}
```

Every later request carries `Mcp-Session-Id` and, once the client has initialized, `MCP-Protocol-Version`.

**Configuring** what is served and who may reach it, in `config/sse.php`:

```php
return [
    'allowed_hosts' => ['localhost', '127.0.0.1', '[::1]', 'mcp.internal'],
    'session' => ['idle_timeout' => 900],
];
```

## Requirements

| | |
|---|---|
| PHP | 8.4.27 or newer in 8.4, or 8.5.12 or newer. |
| VenusianPHP | A 0.11 application with `venusian-mariner/auth`, `http`, `nuts-and-bolts`, `routing`, `session` and `view`, which `venusian/serve` provides, and `venusian-voyager/contracts`, `database`, `http` and `nuts-and-bolts`. |
| superconductor/core | 0.11. This package adds the transport only. |
| A server | `venusian/serve` 0.11.1 or later (`composer.json` conflicts with older) serving the application: `php computer serve`, or an `HttpServer` a sketch attaches to its own loop. It runs several requests at once (`serve.max_in_flight`, 10 by default), which a tool that waits for the user's answer needs. |
| Protocol | The versions superconductor/core is configured for: 2025-11-25, 2025-06-18, 2025-03-26 and 2024-11-05. |

## Installation

```bash
composer require superconductor/core superconductor/web
```

The provider is discovered. It merges `config/sse.php`, registers the controllers and middleware in the container, aliases the middleware on the router as `mcp.<key>` and loads `routes/web.php`. Publish the config to change any of it:

```bash
php computer vendor:publish --tag=sse-config
```

[Authorization](#authorization) keeps its tokens, clients and codes in the database. The package's migrations have no date: publishing copies them into `database/migrations` with one, and `migrate` runs them from there. The app owns them once published, and a second publish finds them rather than adding copies. They reference `users`, so publish serve's users migration first if the app does not have one:

```bash
php computer vendor:publish --tag=mariner-migrations
php computer vendor:publish --tag=sse-migrations
php computer migrate
```

## Configuration

`config/sse.php`:

| Key | Default | What it does |
|---|---|---|
| `routes` | `null` | The routes file the provider loads; `null` loads the package's own `routes/web.php`. |
| `path` | `mcp/{server}` | The endpoint. `{server}` is the name of a registered `MCPServer`. |
| `controllers.messages` / `.stream` / `.session` | the package's three | The class behind POST, GET and DELETE. An application's own class named here is the one that answers. |
| `controllers.metadata` | the package's | The class behind the protected resource metadata route, which exists only when `authorization.guard` is set. |
| `controllers.oauth_*` | the package's | The classes behind the built-in authorization server's routes: `oauth_metadata`, `oauth_authorize`, `oauth_token`, `oauth_register`, `oauth_revoke`, `oauth_login`. |
| `middleware` | `host`, `auth`, `protocol`, `session` | Every entry runs on the endpoint, in this order, under the router alias `mcp.<key>`. |
| `allowed_hosts` | `localhost`, `127.0.0.1`, `[::1]` | The hosts the `Host` and `Origin` headers may name; `*` allows any. |
| `authorization.guard` | `MCP_AUTH_GUARD`, unset | The auth guard that checks each request's bearer token; unset leaves the endpoint open, unless the built-in server is on, which makes it `mcp`. `mcp` is the package's guard over `mcp_access_tokens`. See [Authorization](#authorization). |
| `authorization.servers` | `MCP_AUTHORIZATION_SERVERS`, comma-separated | Other authorization servers the metadata lists, after the built-in one when it is on. |
| `authorization.scopes` | `[]` | Scopes every token must carry; advertised as `scopes_supported`. |
| `authorization.server.enabled` | `MCP_AUTHORIZATION_SERVER`, `false` | Run the built-in OAuth 2.1 authorization server. |
| `authorization.server.prefix` | `oauth` | Its endpoints: `oauth/authorize`, `/token`, `/register`, `/revoke`, `/login`. |
| `authorization.server.session_guard` | `web` | The session guard people sign in with before they approve a client. |
| `authorization.server.login` | `null` | The app's login page, as a route name or a path; `null` uses the package's email and password page at `oauth/login`. |
| `authorization.server.registration` | `true` | Dynamic client registration (RFC 7591). |
| `authorization.server.client_metadata_documents` | `true` | Accept a `client_id` that is the https URL of a client metadata document. |
| `authorization.server.code_ttl` / `.access_ttl` / `.refresh_ttl` | `60` / `3600` / `2592000` | Seconds an authorization code, an access token and a refresh token live. |
| `session.idle_timeout` | `1800` | Seconds a session may go without a request or an open stream before it is forgotten. |
| `session.poll_interval` | `0.1` | Seconds between two looks at the server's queued notifications on an open GET stream. |
| `session.replay_events` | `100` | Events each stream keeps after writing them, for a client that resumes it with `Last-Event-ID`. |
| `notifications` | `initialized`, `cancelled` | Method to Action class (`Fabricate\Mechanism\Session\Actions`) for notifications a client sends. |

## The endpoint

| Request | Answer |
|---|---|
| `POST` with an `initialize` request | `200`, an event stream carrying the response, and an `Mcp-Session-Id` header. A refused initialize leaves no session behind. |
| `POST` with requests | `200`, an event stream that also carries what the server sends while they run (progress, log messages, elicitation) and ends once every request is answered or cancelled. |
| `POST` with only notifications or responses | `202`, no body. |
| `GET` | `200`, an event stream for what the server sends outside any request; it stays open until the session ends or the client hangs up. |
| `GET` with `Last-Event-ID` | `200`, the stream that id names (a GET's or a POST's) resumed after it: only what it missed on that stream, then the rest as it comes. Every event has an `id`, and a 2025-11-25 stream opens with one and no data, so a client that drops a stream can always come back for it. |
| `DELETE` | `204`, and the session is forgotten. |

Refusals: `403` for a `Host` or `Origin` that is not allowed, `401` or `403` from [authorization](#authorization) when it is on, `400` for an unsupported `MCP-Protocol-Version`, `400` for a request with no `Mcp-Session-Id` (except to initialize on a registered server), `404` for an unknown session id, a session id used on another server or by another user, or an unknown `{server}`, `406` when requests are POSTed or a stream is asked for without `Accept: text/event-stream`, and `400` for a batch on a session that does not take batches (2025-06-18).

Each session runs its own instance of the server it was opened on. Sessions are kept in memory by the process that serves them.

## Authorization

Turn it on with `MCP_AUTHORIZATION_SERVER=true` and the application is both the MCP server's OAuth protected resource and its authorization server, as the 2025-11-25 specification describes. Clients find everything themselves: the 401 names the endpoint's metadata, which names the authorization server, whose metadata names its endpoints.

1. A client calls `POST /mcp/{server}` without a token and gets `401` with `WWW-Authenticate: Bearer resource_metadata="…"`.
2. It reads `GET /.well-known/oauth-protected-resource/mcp/{server}` and `GET /.well-known/oauth-authorization-server`.
3. It registers at `POST /oauth/register` (RFC 7591), or uses the https URL of its client metadata document as its `client_id`.
4. It opens `GET /oauth/authorize` in the person's browser, with PKCE (`S256` only) and `resource` set to the endpoint's URL. A person who is not signed in goes to the login page and comes back. They see the client's name, where they will be sent afterwards, the endpoint and the scopes, and approve or deny. A client that registered itself is labelled as unverified; a metadata-document client is shown by its host.
5. The client exchanges the code at `POST /oauth/token` and calls the endpoint with `Authorization: Bearer …`. Refresh tokens are rotated on every use.

The rules it holds to:

- A code works once. A code or refresh token used a second time revokes every token of that grant, since it means one leaked.
- A token is bound to one server's endpoint on this host (RFC 8707). A token for `weather` is refused by `mcp/notes`.
- Redirect URIs must match what the client registered, except that a loopback `http` URI may use any port (RFC 8252). Plain `http` is refused anywhere else.
- An error with an unknown client or redirect URI is shown to the person, never sent to the URI. Other errors go to the client with `state` and `iss` (RFC 9207).
- Consent and login pages refuse to be framed and need the form's CSRF token. Login allows 5 tries a minute, registration 10, and token and revocation 60.
- Client metadata documents are fetched over https only, from a public address, without redirects, and up to 5 KiB.
- `POST /oauth/revoke` (RFC 7009) ends a grant by its refresh token, or one access token.
- Scopes the server does not know are ignored. Asking for none of the known ones grants them all. A token missing a scope from `authorization.scopes` gets `403` with `error="insufficient_scope"`.
- A session belongs to the user who opened it. Anyone else's request on it is a `404`.

People sign in with the `web` session guard against the `users` table, which comes from serve's users migration. To use your own login page instead, set `authorization.server.login` to its route name or path. It must send the person back with `redirect()->intended()`. The views are published with `php computer vendor:publish --tag=sse-views`.

### Without the built-in server

Set `MCP_AUTH_GUARD=mcp` alone and the endpoint checks tokens without issuing any. List the servers your clients get tokens from in `MCP_AUTHORIZATION_SERVERS`, or issue tokens in your application:

```php
use SuperconductorMCP\SSE\Auth\AccessToken;
use SuperconductorMCP\SSE\Auth\ProtectedResource;

$token = AccessToken::issue($user, ProtectedResource::uri('weather'), ['mcp:tools'], now()->addDays(30));
// give $token to the client once: only its hash is stored
```

`ProtectedResource::uri()` builds the endpoint's URL from `webapp.url`; pass the scheme and host as a second argument if clients reach the app at another address. The `mcp` guard looks users up through the `users` provider in `config/auth.php`. To check JWTs or ask an introspection endpoint instead, define your own guard and name it in `authorization.guard`. That guard must check the token was issued for this endpoint. If `authorization.scopes` lists any scopes, it must also put a `SuperconductorMCP\SSE\Auth\BearerToken` on the request as `mcp.token`. The built-in server refuses to start with a guard that does not use the `mcp-token` driver, since that guard would refuse the server's tokens.

A `config/sse.php` published before authorization was added has no `auth` middleware. Add `'auth' => Authenticate::class` after `host`, or the app refuses to boot rather than serve an open endpoint.

## Packages you might use with this one

| Package | Gives |
|---|---|
| [superconductor/core](https://github.com/superconductor-mcp/core) | The protocol: servers, clients, messages, procedures. Required. |
| [superconductor/stdio](https://github.com/superconductor-mcp/stdio) | The stdio transport, for servers a harness starts as a child process. |

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Agentic Documentation

This repository carries an [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) bundle at [`.okf/`](.okf/): Markdown with YAML frontmatter, readable by you and loadable by a coding agent. It records what the README doesn't: how a request becomes a session and a stream, why a session's streams and in-flight requests keep it alive, what the guards refuse and in what order, and a log of every change that altered behaviour.

Start at [`.okf/index.md`](.okf/index.md). [`AGENTS.md`](AGENTS.md) is the short rule sheet an agent reads before touching the controllers, the middleware or the sessions; each rule points into the bundle for the reason behind it.

Point your agent at the repo and it has the same context the maintainer has. Point yourself at it when the README's answer is "it does X" and you want "because".

## Resources

* [MCP transports, 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [2025-06-18](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports): the Streamable HTTP rules this package follows, including the session header, the protocol version header, the Origin check and resuming a stream.
* [superconductor/core](https://github.com/superconductor-mcp/core): the protocol this transport carries.
* [VenusianPHP](https://github.com/VenusianPHP): the router, the HTTP server and the IOPools loop.
* [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md): the spec the [`.okf/`](.okf/) bundle follows.

## Running the test suite

The suite is Pest v4. It boots a fixture application, attaches a real `HttpServer` on a free port and talks to it over raw sockets, so every case runs the route, the middleware, the controller and the session end to end. No test needs a terminal or a network. `venusian/serve` and `venusian/framework` are in `require-dev`; to develop against local checkouts, copy `composer.json` to a gitignored `composer.dev.json` with symlinked path repositories to `../core`, `venusian/serve` and `venusian/framework`, then install with `COMPOSER=composer.dev.json`.

```bash
git clone https://github.com/superconductor-mcp/web.git
cd web
composer install
vendor/bin/pest
```

```console
Tests:    27 passed (78 assertions)
```

## Contributing

Open an issue first for anything beyond a typo. Pull requests come with a Pest test for the behaviour and keep CI green. A protocol change goes to superconductor/core first; this package moves messages over HTTP. Read [`AGENTS.md`](AGENTS.md) for the rules the code follows.

## Security

The endpoint is reachable by whatever can reach the port serve listens on; it checks `Host` and `Origin` against an allow-list and binds to localhost by default. See [SECURITY.md](SECURITY.md) for the model and how to report a vulnerability.

## License

MIT. See [LICENSE](LICENSE).
