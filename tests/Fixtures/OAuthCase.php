<?php

namespace SuperconductorMCP\SSE\Tests\Fixtures;

use Mariner\Core\Testing\TestCase;
use Mariner\Testing\TestResponse;
use Voyager\Core\RenderedInstance;

/**
 * The fixture app with the built-in authorization server enabled, requests made through the HTTP kernel, and a
 * cookie jar so one test is one browser: the session and login carry from request to request.
 */
abstract class OAuthCase extends TestCase
{
    /** @var array<string, mixed> the app's config/sse.php for the next boot */
    public static array $sse = [];

    /** @var array<string, string> */
    public array $jar = [];

    // the CSRF token of the last form a page showed, which the browser posts back with it
    public ?string $csrf = null;

    public function createApplication(): RenderedInstance
    {
        McpApp::$sse = static::$sse ?: self::defaults();
        $app = McpApp::boot();
        McpApp::$sse = [];

        return $app;
    }

    /** @return array<string, mixed> */
    public static function defaults(array $server = []): array
    {
        return ['authorization' => [
            'guard' => null,
            'servers' => [],
            'scopes' => ['mcp:tools', 'mcp:resources'],
            'server' => ['enabled' => true, 'prefix' => 'oauth', 'session_guard' => 'web', 'login' => null, 'registration' => true, 'client_metadata_documents' => true, 'code_ttl' => 60, 'access_ttl' => 3600, 'refresh_ttl' => 2592000, ...$server],
        ]];
    }

    /** Boot again with $sse as the app's config/sse.php. */
    public function rebootWith(array $sse): void
    {
        static::$sse = $sse;

        try {
            $this->tearDown();
            $this->setUp();
        } finally {
            static::$sse = [];
        }
    }

    /** A request as the browser makes it: with the cookies earlier responses set and a form's CSRF token, keeping what this one sets. */
    public function browse(string $method, string $uri, array $data = []): TestResponse
    {
        if ($method === 'POST' && ! is_null($this->csrf)) {
            $data += ['_token' => $this->csrf];
        }

        $response = $this->withUnencryptedCookies($this->jar)->call($method, $uri, $data);

        if (preg_match('/name="_token" value="([^"]+)"/', (string) $response->getContent(), $match)) {
            $this->csrf = $match[1];
        }

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }
}
