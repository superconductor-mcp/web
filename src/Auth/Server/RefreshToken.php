<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Voyager\Database\Instrument\Model;

/**
 * A refresh token, in `mcp_refresh_tokens` as a SHA-256 hash. Each use rotates it (OAuth 2.1 §4.3.1, required for
 * public clients): the old one is revoked and a new one issued. A revoked one presented again means it leaked,
 * so its whole grant is revoked.
 *
 * @property string $token
 * @property string $grant_id
 * @property string $client_id
 * @property mixed $user_id
 * @property list<string> $abilities
 * @property string $resource
 * @property \DateTimeInterface|null $revoked_at
 * @property \DateTimeInterface $expires_at
 */
class RefreshToken extends Model
{
    protected $table = 'mcp_refresh_tokens';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'revoked_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
