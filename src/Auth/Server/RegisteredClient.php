<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Voyager\Database\Instrument\Model;

/**
 * A client that registered itself (RFC 7591), in `mcp_oauth_clients`; its secret, if it has one, as a SHA-256 hash.
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $secret
 * @property list<string> $redirect_uris
 * @property list<string> $grant_types
 */
class RegisteredClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'grant_types' => 'array'];
    }

    public function toClient(): Client
    {
        return new Client($this->id, $this->name, $this->redirect_uris, $this->grant_types, $this->secret);
    }
}
