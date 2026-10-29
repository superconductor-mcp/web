<?php

namespace SuperconductorMCP\SSE\Sessions;

use Fabricate\Contracts\Server\MCPServer;
use Fabricate\Mechanism\Session\Session;
use Fabricate\Mechanism\Session\SessionBag;

// one client's session with one server over HTTP, named by its Mcp-Session-Id
final class HttpSession
{
    public float $last_seen;

    // GET streams open on this session; a session with one open is never idle
    public int $streams = 0;

    // ended by DELETE or the idle timeout; open streams end on their next look
    public bool $closed = false;

    public function __construct(
        public readonly string $id,
        public readonly string $server,
        public readonly Session $session,
    ) {
        $this->last_seen = microtime(true);
    }

    public function bag(): SessionBag
    {
        return $this->session->bag;
    }

    // tools that ask for their server get this session's instance; a handler is built before it first waits
    public function bindServer(): void
    {
        $server = $this->bag()->server_configuration;
        app()->registerInstance($server::class, $server);
        app()->registerInstance(MCPServer::class, $server);
    }
}
