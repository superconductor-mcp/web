<?php

namespace SuperconductorMCP\SSE\Auth;

/**
 * The token a guard accepted, put on the request as `mcp.token` so the endpoint can check its scopes.
 * The package's own AccessToken is one; a guard that checks tokens elsewhere (a JWT, introspection)
 * sets its own when `sse.authorization.scopes` asks for any.
 */
interface BearerToken
{
    public const string ATTRIBUTE = 'mcp.token';

    /** @return list<string> */
    public function scopes(): array;
}
