<?php

namespace SuperconductorMCP\SSE\Auth;

/**
 * Each MCP server's endpoint is an OAuth protected resource (RFC 9728), named by its canonical URI: the
 * endpoint's absolute URL without query or fragment. Tokens are bound to it (RFC 8707), and its metadata is
 * served at the well-known path with the endpoint's path appended.
 */
final class ProtectedResource
{
    /** The canonical URI of $server's endpoint under $base (scheme and host, e.g. https://example.com); $base defaults to webapp.url. */
    public static function uri(string $server, ?string $base = null): string
    {
        $base = strtolower(rtrim($base ?? (string) config('webapp.url'), '/'));

        return $base.'/'.str_replace('{server}', $server, trim((string) config('sse.path'), '/'));
    }

    /** Where $server's metadata is served under $base. */
    public static function metadataUrl(string $server, string $base): string
    {
        return strtolower(rtrim($base, '/')).'/.well-known/oauth-protected-resource/'.str_replace('{server}', $server, trim((string) config('sse.path'), '/'));
    }
}
