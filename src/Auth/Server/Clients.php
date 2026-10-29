<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Mariner\Http\Request;
use Throwable;
use Voyager\Http\Client\Factory as Http;

/**
 * Finds, authenticates and registers clients. A client_id that is an https URL with a path is a client metadata
 * document (2025-11-25, SEP-991), fetched when the client asks for authorization; any other is a registered client.
 */
class Clients
{
    // SEP-991: an authorization server should cap what it reads of a metadata document
    public const int DOCUMENT_BYTES = 5120;

    public function __construct(protected readonly Http $http) {}

    public static function isMetadataDocument(string $client_id): bool
    {
        $parts = parse_url($client_id);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'])
            && ! isset($parts['fragment'])
            && ($parts['path'] ?? '/') !== '/'
            && ! preg_match('#(^|/)\.\.?(/|$)#', $parts['path'] ?? '');
    }

    /**
     * The client for $client_id, fetching its metadata document if it is one.
     *
     * @throws OAuthError invalid_client: unknown, or a document that cannot be fetched or is not valid
     */
    public function find(string $client_id): Client
    {
        if (self::isMetadataDocument($client_id)) {
            if (! config('sse.authorization.server.client_metadata_documents', true)) {
                throw new OAuthError('invalid_client', 'Client metadata documents are not accepted here.', 401);
            }

            return $this->fetch($client_id);
        }

        $registered = RegisteredClient::query()->find($client_id);

        return $registered?->toClient() ?? throw new OAuthError('invalid_client', 'Unknown client.', 401);
    }

    /**
     * The client a token or revocation request authenticates as (RFC 6749 §2.3): HTTP Basic, client_secret in the
     * body, or a public client's client_id alone. A metadata-document client is public, so it is not fetched again;
     * the code or refresh token it presents is bound to its client_id.
     *
     * @throws OAuthError invalid_request or invalid_client
     */
    public function authenticate(Request $request): Client
    {
        $basic = $this->basicCredentials($request);
        $posted = $request->request->has('client_secret');

        if (! is_null($basic) && $posted) {
            throw new OAuthError('invalid_request', 'Use one client authentication method, not both.');
        }

        [$client_id, $secret] = $basic ?? [(string) $request->request->get('client_id', ''), $posted ? (string) $request->request->get('client_secret') : null];

        if ($client_id === '') {
            throw new OAuthError('invalid_client', 'The request names no client.', 401);
        }

        if (self::isMetadataDocument($client_id)) {
            if (! is_null($secret) || ! config('sse.authorization.server.client_metadata_documents', true)) {
                throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
            }

            return new Client($client_id, null, []);
        }

        $client = RegisteredClient::query()->find($client_id)?->toClient();

        $authenticated = ! is_null($client) && ($client->confidential()
            ? ! is_null($secret) && hash_equals((string) $client->secret_hash, hash('sha256', $secret))
            : is_null($secret));

        if (! $authenticated) {
            throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    /**
     * Dynamic client registration (RFC 7591): the stored client's metadata, with its secret when it is confidential.
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError invalid_redirect_uri or invalid_client_metadata
     */
    public function register(mixed $metadata): array
    {
        if (! is_array($metadata) || array_is_list($metadata)) {
            throw new OAuthError('invalid_client_metadata', 'The registration must be a JSON object.');
        }

        $redirect_uris = $metadata['redirect_uris'] ?? null;

        if (! is_array($redirect_uris) || $redirect_uris === [] || ! array_is_list($redirect_uris)) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list at least one URI.');
        }

        foreach ($redirect_uris as $uri) {
            if (! is_string($uri) || ! is_null($problem = self::redirectProblem($uri))) {
                throw new OAuthError('invalid_redirect_uri', is_string($uri) ? "{$uri}: {$problem}" : 'Each redirect URI must be a string.');
            }
        }

        $method = $metadata['token_endpoint_auth_method'] ?? 'client_secret_basic';
        $grant_types = $metadata['grant_types'] ?? ['authorization_code'];
        $response_types = $metadata['response_types'] ?? ['code'];
        $name = $metadata['client_name'] ?? null;

        if (! in_array($method, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_basic or client_secret_post.');
        }

        if (! is_array($grant_types) || $grant_types === [] || array_diff($grant_types, ['authorization_code', 'refresh_token']) !== [] || ! in_array('authorization_code', $grant_types, true)) {
            throw new OAuthError('invalid_client_metadata', 'grant_types must include authorization_code, and may add refresh_token only.');
        }

        if ($response_types !== ['code']) {
            throw new OAuthError('invalid_client_metadata', 'response_types must be ["code"].');
        }

        if (! is_null($name) && (! is_string($name) || mb_strlen($name) > 255)) {
            throw new OAuthError('invalid_client_metadata', 'client_name must be a string of at most 255 characters.');
        }

        $id = 'mcp_'.bin2hex(random_bytes(16));
        $secret = $method === 'none' ? null : bin2hex(random_bytes(32));
        $grant_types = array_values(array_unique($grant_types));

        RegisteredClient::query()->create([
            'id' => $id,
            'name' => $name,
            'secret' => is_null($secret) ? null : hash('sha256', $secret),
            'redirect_uris' => $redirect_uris,
            'grant_types' => $grant_types,
        ]);

        return array_filter([
            'client_id' => $id,
            'client_id_issued_at' => time(),
            'client_secret' => $secret,
            'client_secret_expires_at' => is_null($secret) ? null : 0,
            'client_name' => $name,
            'redirect_uris' => $redirect_uris,
            'grant_types' => $grant_types,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], fn (mixed $value): bool => ! is_null($value));
    }

    /**
     * What is wrong with $uri as a redirect URI, or null: absolute, no fragment, and not plain http except to the
     * loopback interface (OAuth 2.1 §2.3.1); no scheme that runs or reads local content.
     */
    public static function redirectProblem(string $uri): ?string
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));

        return match (true) {
            $scheme === '' || ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $uri) => 'must be an absolute URI.',
            str_contains($uri, '#') => 'must not have a fragment.',
            in_array($scheme, ['javascript', 'data', 'file', 'vbscript'], true) => 'must not use the '.$scheme.' scheme.',
            $scheme === 'http' && ! Client::isLoopback($uri) => 'must use https, except on the loopback interface.',
            in_array($scheme, ['http', 'https'], true) && ! parse_url($uri, PHP_URL_HOST) => 'must name a host.',
            default => null,
        };
    }

    /** Fetch and check a client metadata document: https, a public address only, no redirects, at most 5 KiB. */
    protected function fetch(string $client_id): Client
    {
        $host = (string) parse_url($client_id, PHP_URL_HOST);
        $port = (int) (parse_url($client_id, PHP_URL_PORT) ?? 443);
        $addresses = $this->addresses(trim($host, '[]'));

        // SSRF: a client_id must not make this server fetch from its own network
        if ($addresses === [] || array_filter($addresses, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) !== []) {
            throw new OAuthError('invalid_client', "The client metadata document's host [{$host}] does not resolve to a public address.", 401);
        }

        try {
            $response = $this->http->timeout(5)->withoutRedirecting()->accept('application/json')->withOptions([
                // the connection goes to the address just checked, so a second DNS answer cannot point it elsewhere
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0])],
                    // stop reading past the cap rather than buffer whatever the host sends
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => fn ($handle, int $expected, int $received): int => (int) ($received > self::DOCUMENT_BYTES),
                ],
            ])->get($client_id);
        } catch (Throwable $e) {
            throw new OAuthError('invalid_client', 'The client metadata document could not be fetched.', 401);
        }

        $body = $response->body();

        if (! $response->successful() || strlen($body) > self::DOCUMENT_BYTES) {
            throw new OAuthError('invalid_client', 'The client metadata document could not be fetched, or is larger than 5 KiB.', 401);
        }

        $document = json_decode($body, true);
        $redirect_uris = is_array($document) ? ($document['redirect_uris'] ?? null) : null;

        $problem = match (true) {
            ! is_array($document) || array_is_list($document) => 'is not a JSON object',
            ($document['client_id'] ?? null) !== $client_id => 'names a different client_id',
            ! is_array($redirect_uris) || $redirect_uris === [] || ! array_is_list($redirect_uris) || array_filter($redirect_uris, fn (mixed $uri) => ! is_string($uri) || ! is_null(self::redirectProblem($uri))) !== [] => 'has no valid redirect_uris',
            ($document['token_endpoint_auth_method'] ?? 'none') !== 'none' || isset($document['client_secret']) => 'asks for client authentication this server does not support (only none)',
            isset($document['client_name']) && ! is_string($document['client_name']) => 'has a client_name that is not a string',
            default => null,
        };

        if (! is_null($problem)) {
            throw new OAuthError('invalid_client', "The client metadata document {$problem}.", 401);
        }

        $grant_types = is_array($document['grant_types'] ?? null) ? array_values(array_intersect($document['grant_types'], ['authorization_code', 'refresh_token'])) : ['authorization_code'];

        return new Client($client_id, isset($document['client_name']) ? mb_substr($document['client_name'], 0, 255) : null, $redirect_uris, $grant_types);
    }

    /**
     * Every address $host resolves to (an IP literal is itself).
     *
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    /** @return array{0: string, 1: string}|null client_id and secret from HTTP Basic, each form-urldecoded (RFC 6749 §2.3.1) */
    private function basicCredentials(Request $request): ?array
    {
        $header = (string) $request->headers->get('Authorization');

        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            throw new OAuthError('invalid_client', 'Malformed HTTP Basic credentials.', 401);
        }

        [$id, $secret] = explode(':', $decoded, 2);

        return [urldecode($id), urldecode($secret)];
    }
}
