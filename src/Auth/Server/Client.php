<?php

namespace SuperconductorMCP\SSE\Auth\Server;

/**
 * A client the authorization server knows: one that registered (RFC 7591) or one whose client_id is the URL of
 * its metadata document (2025-11-25, SEP-991). A client with a secret is confidential and must authenticate at
 * the token endpoint; one without is public and proves itself with PKCE alone.
 */
final class Client
{
    /**
     * @param  list<string>  $redirect_uris
     * @param  list<string>  $grant_types
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly array $redirect_uris,
        public readonly array $grant_types = ['authorization_code', 'refresh_token'],
        public readonly ?string $secret_hash = null,
    ) {}

    public function confidential(): bool
    {
        return ! is_null($this->secret_hash);
    }

    public function allows(string $grant_type): bool
    {
        return in_array($grant_type, $this->grant_types, true);
    }

    /**
     * Whether $uri is one of the client's redirect URIs: the same string, except that a loopback http URI may name
     * any port (RFC 8252 §7.3), since a native client listens on whichever port is free.
     */
    public function redirectsTo(string $uri): bool
    {
        foreach ($this->redirect_uris as $registered) {
            if ($registered === $uri || (self::isLoopback($registered) && self::withoutPort($registered) === self::withoutPort($uri))) {
                return true;
            }
        }

        return false;
    }

    public static function isLoopback(string $uri): bool
    {
        return parse_url($uri, PHP_URL_SCHEME) === 'http'
            && in_array(strtolower((string) parse_url($uri, PHP_URL_HOST)), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private static function withoutPort(string $uri): string
    {
        return (string) preg_replace('#^(http://[^/:?\#]+|http://\[[^\]]+\]):\d+#i', '$1', $uri);
    }

    /** The name a person approving the client sees: its own name, else its client_id's host. */
    public function displayName(): string
    {
        return $this->name ?? (string) (parse_url($this->id, PHP_URL_HOST) ?: $this->id);
    }
}
