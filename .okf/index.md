---
okf_version: "0.2"
---

# superconductor/web

* [Authorization](architecture/authorization.md) - The endpoint as an OAuth protected resource and the built-in OAuth 2.1 authorization server: discovery, registration, metadata-document clients, consent, PKCE codes, rotated refresh tokens, revocation, published migrations.
* [Transport](architecture/transport.md) - What this package owns (the routes, three controllers, the guards, the event stream) and what it leaves to superconductor/core and venusian/serve.

# Server

* [Sessions](server/sessions.md) - `HttpSessions` and `HttpSession`: opened on initialize, found by id and server, kept alive by streams and requests in flight, forgotten on DELETE or idle.

# Reference

* [Config](reference/config.md) - Every key in `config/sse.php` and who reads it.
* [Log](log.md) - Changes that altered behaviour.
