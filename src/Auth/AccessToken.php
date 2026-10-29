<?php

namespace SuperconductorMCP\SSE\Auth;

use DateTimeInterface;
use Mariner\Contracts\Auth\Authenticatable;
use Mariner\Contracts\Auth\UserProvider;
use Mariner\Http\Request;
use Voyager\Database\Instrument\Model;

/**
 * A bearer token for one server's endpoint, kept in `mcp_access_tokens` as a SHA-256 hash: whoever issues
 * it (the app, or an authorization server sharing the database) gets the plain token once, from issue().
 *
 * @property int $id
 * @property mixed $user_id
 * @property string|null $client_id
 * @property string|null $grant_id
 * @property string $name
 * @property string $token
 * @property list<string> $abilities
 * @property string $resource
 * @property \DateTimeInterface|null $last_used_at
 * @property \DateTimeInterface|null $expires_at
 */
class AccessToken extends Model implements BearerToken
{
    protected $table = 'mcp_access_tokens';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * A token for $user on $resource (a canonical URI, from ProtectedResource::uri()); the plain token, which is not kept.
     *
     * @param  list<string>  $scopes
     */
    public static function issue(Authenticatable $user, string $resource, array $scopes = [], ?DateTimeInterface $expires_at = null, string $name = 'mcp'): string
    {
        return static::store(['user_id' => $user->getAuthIdentifier(), 'name' => $name, 'abilities' => array_values($scopes), 'resource' => $resource, 'expires_at' => $expires_at]);
    }

    /**
     * Stores a token with $attributes (user_id, name, abilities, resource, expires_at, and client_id and grant_id
     * from the authorization server); the plain token.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function store(array $attributes): string
    {
        $plain = bin2hex(random_bytes(32));
        static::query()->create([...$attributes, 'token' => hash('sha256', $plain)]);

        return $plain;
    }

    /**
     * The `mcp-token` guard: the user of the request's bearer token, if it is known, unexpired, and issued
     * for this endpoint (a token for another server or host is refused: RFC 8707 audience). The token goes on
     * the request as `mcp.token`.
     */
    public static function authenticate(Request $request, ?UserProvider $users): ?Authenticatable
    {
        $plain = $request->bearerToken();

        if (is_null($plain) || $plain === '' || is_null($users)) {
            return null;
        }

        /** @var static|null $token */
        $token = static::query()->where('token', hash('sha256', $plain))->first();
        $resource = ProtectedResource::uri((string) $request->route('server'), $request->getSchemeAndHttpHost());

        if (is_null($token) || $token->resource !== $resource || ($token->expires_at?->getTimestamp() ?? PHP_INT_MAX) <= time()) {
            return null;
        }

        $user = $users->retrieveById($token->user_id);

        if (! is_null($user)) {
            $token->forceFill(['last_used_at' => now()])->save();
            $request->attributes->set(self::ATTRIBUTE, $token);
        }

        return $user;
    }

    public function scopes(): array
    {
        return $this->abilities ?? [];
    }
}
