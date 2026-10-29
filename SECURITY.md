# Security Policy

## Supported versions

superconductor/web is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[superconductor-mcp/web](https://github.com/superconductor-mcp/web) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, the protocol version in play, and steps to reproduce.
Reports are read and weighed for the release line in development; before 1.0 there is no
response-time commitment.

## Security model

superconductor/web is the Streamable HTTP transport for superconductor/core: it adds `POST`, `GET` and
`DELETE /mcp/{server}` to the application's router. The protocol checks (decoding, lifecycle, ids,
capabilities) are superconductor/core's and are described in
[its SECURITY.md](https://github.com/superconductor-mcp/core/blob/main/SECURITY.md). This package adds
the endpoint, its guards and its sessions.

- **The endpoint is as reachable as the server that serves it.** venusian/serve listens on `127.0.0.1`
  unless `serve.host` says otherwise; this package adds no authentication. An application that serves
  beyond localhost puts its own middleware in front, through `sse.middleware` or its router.
- **Host and Origin are checked on every method.** Both must name a host in `sse.allowed_hosts`
  (`localhost`, `127.0.0.1` and `[::1]` by default; ports and case are ignored) or the request gets
  `403`. This is the DNS-rebinding defence the 2025-06-18 transport specification requires. A `*` in the
  list turns it off, so use it only where something in front enforces the same rule.
- **A session id is a bearer token.** It is 32 hex characters from `random_bytes(16)`, held in the
  memory of the process that opened it, and valid only on the server it was opened on. Anyone who holds
  it can use that session until it is deleted or idle for `sse.session.idle_timeout` seconds. Serve
  the endpoint over TLS (a proxy in front of serve) wherever the header could be read in transit.
- **A request without a session is refused** (`400`), except an `initialize` on a registered server;
  an unknown session id or server is `404`. A refused initialize leaves no session behind.
- **The protocol version is checked on every request** that names one: an unsupported
  `MCP-Protocol-Version` is `400`.
- **Sessions are bounded in time, not in number.** A session with no request, no open stream and no
  request in flight is forgotten after `sse.session.idle_timeout`; nothing here caps how many an
  allowed client may open. Cap connections and requests in front of the endpoint where that matters.
- **Tools run with the application's privileges**, as in superconductor/core: what a tool does once
  called is the application's code.

A report is in scope when this package misbehaves on well-formed or malformed requests: a request that
reaches a controller past a guard that should have refused it, a session id accepted on the wrong server
or after DELETE, an Origin or Host that passes the check while naming a host outside the list, or a
stream that carries one session's messages to another. What a tool, resource or prompt does once
called is the application's code.
