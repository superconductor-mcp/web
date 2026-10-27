# Security Policy

## Supported versions

semiconductor/sse is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## The server's model

`McpHttpServer` binds loopback only and is meant to be switched on by a desktop application. Its lock is one bearer token compared in constant time; a request without it is a 401 before any body is read into the protocol. `Origin` from anywhere but localhost is a 403, which is the DNS-rebinding guard the spec asks for. Request heads and bodies are bounded (`web.limits`), a head that never completes is a 408, and every response closes its connection. There is no TLS, no user model and no OAuth: a token that leaks is a token to rotate, and anything on the same machine that can read it can drive the server. Do not bind it to `0.0.0.0`.

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[superconductor-mcp/sse](https://github.com/superconductor-mcp/sse) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, the protocol version in play, the transport, and steps
to reproduce. Reports are read and weighed for the release line in development; before 1.0 there
is no response-time commitment.
## Security model

semiconductor/sse is the HTTP with SSE transport for superconductor/mcp. The protocol checks (decoding,
lifecycle, ids, capabilities) are superconductor/mcp's and are described in
[its SECURITY.md](https://github.com/superconductor-mcp/mcp/blob/main/SECURITY.md). This release is the
client end only.

- **The client talks to the url it is given.** `open()` connects to the entry's host and sends one GET;
  every message is a POST to the endpoint the server named in its first event. The connection follows
  no redirects and opens no other connection.
- **Credentials are the entry's `headers`.** They go on the GET and on every POST, and nowhere else.
  Treat `config/mcp.php` as code: a writable config file is a writable credential.
- **https verifies the peer.** The TLS context sets `peer_name` to the url's host and leaves PHP's
  verification on. There is no switch to turn it off.
- **Everything from the server is data.** The endpoint is a path, not a command; each event's `data`
  goes through superconductor/mcp's decoder like a stdio line. A server that answers the stream with
  anything but `200 text/event-stream`, or a POST with anything but `2xx`, ends the connection.

A report is in scope when the connection sends a header or a message somewhere other than the
configured host and the endpoint it named, leaks the entry's headers, or lets a frame through that
superconductor/mcp's decoder would have refused.
