<?php

namespace SuperconductorMCP\SSE\Auth\Server;

/** A checked authorization request: who asks, where the answer goes, the PKCE challenge, and what the token will be for. */
final class AuthorizationRequest
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public readonly Client $client,
        public readonly string $redirect_uri,
        public readonly ?string $state,
        public readonly string $code_challenge,
        public readonly array $scopes,
        public readonly string $resource,
    ) {}
}
