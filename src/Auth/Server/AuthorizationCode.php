<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Voyager\Database\Instrument\Model;

/**
 * An authorization code, in `mcp_authorization_codes` as a SHA-256 hash: used once, within `code_ttl`, by the
 * client it was issued to, with the redirect URI and the PKCE verifier of its authorization request.
 *
 * @property string $code
 * @property string $grant_id
 * @property string $client_id
 * @property mixed $user_id
 * @property string $redirect_uri
 * @property string $code_challenge
 * @property list<string> $abilities
 * @property string $resource
 * @property bool $refreshable
 * @property \DateTimeInterface|null $used_at
 * @property \DateTimeInterface $expires_at
 */
class AuthorizationCode extends Model
{
    protected $table = 'mcp_authorization_codes';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'refreshable' => 'boolean', 'used_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
