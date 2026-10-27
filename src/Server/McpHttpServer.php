<?php

namespace SuperconductorMCP\SSE\Server;

use RuntimeException;
use Fabricate\Server\MCPServer;
use Fabricate\Server\ServerRepository;
use Fabricate\Mechanism\ProcedureRouter;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\NutsAndBolts\LineDecoder;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;

/**
 * An MCP server on a local port, 2025-03-26 Streamable HTTP. Made for an application that switches it on from a
 * settings screen: start() joins the running loop, stop() leaves it. Loopback only, one bearer token, one endpoint.
 */
final class McpHttpServer extends WakeSource
{
    public const string NAME = 'mcp.http';

    public const string PATH = '/mcp';

    private const array LOCALHOST = ['localhost', '127.0.0.1', '[::1]'];

    /** @var resource|null */
    private $listening = null;

    private ?MCPServer $server = null;

    private string $token = '';

    private int $port = 0;

    /** @var array<int, Incoming> */
    private array $connections = [];

    /** @var array<string, HttpSession> */
    private array $sessions = [];

    private ?Timer $tick = null;

    public function __construct(
        private readonly Loop $event_loop,
        private readonly ServerRepository $servers,
        private readonly ProcedureRouter $router = new ProcedureRouter(),
    ) {}

    // a token for the application to show in its settings
    public static function token(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * @param MCPServer|string $server the server, or its #[Server] name
     * @param int $port 0 picks a free one; port() says which
     */
    public function start(MCPServer|string $server, int $port, string $token, string $host = '127.0.0.1'): self
    {
        if ($this->running()) {
            throw new RuntimeException('the MCP HTTP server is already running.');
        }

        if ($token === '') {
            throw new RuntimeException('the MCP HTTP server needs a token; McpHttpServer::token() makes one.');
        }

        $this->server = is_string($server) ? $this->servers->get($server) : $server;
        $this->token = $token;

        // tools that ask for their server get this running instance, under its own class or the abstract one
        app()->registerInstance($this->server::class, $this->server);
        app()->registerInstance(MCPServer::class, $this->server);

        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $error);

        if ($socket === false) {
            throw new RuntimeException("could not listen on {$host}:{$port} ({$error}).");
        }

        stream_set_blocking($socket, false);
        $this->listening = $socket;
        $this->port = (int) explode(':', stream_socket_get_name($socket, false))[1];

        $this->event_loop->resource(self::NAME, $this);
        $this->tick = $this->event_loop->every(0.1, function (): void {
            $this->sweep();
        }, self::NAME.'.tick');

        return $this;
    }

    public function stop(): void
    {
        if (! $this->running()) {
            return;
        }

        $this->tick?->cancel();
        $this->tick = null;
        $this->event_loop->forget(self::NAME);

        foreach ($this->sessions as $session) {
            $session->close();
        }

        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->sessions = [];
        $this->connections = [];
        fclose($this->listening);
        $this->listening = null;
        $this->server = null;
    }

    public function running(): bool
    {
        return ! is_null($this->listening);
    }

    public function port(): int
    {
        return $this->port;
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}".self::PATH;
    }

    public function wakes(): array
    {
        if (! $this->running()) {
            return [];
        }

        $wakes = [new Readable($this->listening)];

        foreach ($this->connections as $connection) {
            $wakes[] = $connection->wantsWrite() ? new Writable($connection->socket) : new Readable($connection->socket);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        foreach ($fired as $wake) {
            if (! $this->running()) {
                return;
            }

            $stream = $wake instanceof Readable || $wake instanceof Writable ? $wake->stream : null;

            if ($stream === $this->listening) {
                $this->accept();
            } elseif (isset($this->connections[(int) $stream])) {
                $connection = $this->connections[(int) $stream];
                $wake->kind() === WakeReason::WRITEABLE ? $connection->flush() : $this->read($connection);

                if ($connection->closed()) {
                    unset($this->connections[(int) $stream]);
                }
            }
        }
    }

    private function accept(): void
    {
        // a Readable wake with nobody left in the backlog makes accept warn "timed out"; false is the whole story
        set_error_handler(static fn (): bool => true);

        try {
            while (($socket = stream_socket_accept($this->listening, 0)) !== false) {
                $this->connections[(int) $socket] = new Incoming($socket, (int) config('web.limits.head', 16384), (int) config('web.limits.body', 1048576));
            }
        } finally {
            restore_error_handler();
        }
    }

    private function read(Incoming $connection): void
    {
        $request = $connection->read();

        if (! is_null($request)) {
            $this->handle($request, $connection);
        }
    }

    // the one endpoint: origin and bearer first, then the method decides
    private function handle(Request $request, Incoming $connection): void
    {
        if ($request->path !== self::PATH) {
            $connection->respond(404, 'text/plain', 'not here');

            return;
        }

        // a browser page on another origin reaching a local port is DNS rebinding; harnesses send no Origin
        $origin = $request->header('origin');

        if (! is_null($origin) && ! in_array(strtolower((string) parse_url($origin, PHP_URL_HOST)), self::LOCALHOST, true)) {
            $connection->respond(403, 'text/plain', 'origin not allowed');

            return;
        }

        if (! hash_equals('Bearer '.$this->token, (string) $request->header('authorization'))) {
            $connection->respond(401, 'text/plain', 'unauthorized', ['WWW-Authenticate' => 'Bearer']);

            return;
        }

        $session_id = $request->header('mcp-session-id');
        $session = is_null($session_id) ? null : ($this->sessions[$session_id] ?? null);

        if (! is_null($session_id) && is_null($session)) {
            $connection->respond(404, 'text/plain', 'session not found');

            return;
        }

        match ($request->method) {
            'POST' => $this->post($request, $connection, $session),
            'GET' => is_null($session) ? $connection->respond(400, 'text/plain', 'Mcp-Session-Id required') : $session->listen($connection, $request->header('last-event-id')),
            'DELETE' => is_null($session) ? $connection->respond(400, 'text/plain', 'Mcp-Session-Id required') : $this->endSession($session, $connection),
            default => $connection->respond(405, 'text/plain', 'method not allowed', ['Allow' => 'POST, GET, DELETE']),
        };
    }

    private function post(Request $request, Incoming $connection, ?HttpSession $session): void
    {
        if (! str_contains(strtolower((string) $request->header('content-type')), 'application/json') || json_decode($request->body) === null) {
            $connection->respond(400, 'text/plain', 'a JSON body is required');

            return;
        }

        if (! is_null($session)) {
            $session->post($connection, $request->body);

            return;
        }

        // no session yet: only an initialize starts one, and its reply carries the new id
        $starts = array_filter(LineDecoder::decode($request->body), fn (mixed $m): bool => $m instanceof InitializeRequest);

        if ($starts === []) {
            $connection->respond(400, 'text/plain', 'Mcp-Session-Id required');

            return;
        }

        $id = bin2hex(random_bytes(16));
        $session = new HttpSession($id, $this->server, $this->event_loop, $this->router, (int) config('web.session.replay', 100));
        $this->sessions[$id] = $session;
        $session->post($connection, $request->body, ['Mcp-Session-Id' => $id]);
    }

    private function endSession(HttpSession $session, Incoming $connection): void
    {
        unset($this->sessions[$session->id]);
        $session->close();
        $connection->respond(200, 'text/plain', 'bye');
    }

    // every tick: head deadlines, idle sessions, finished streams, and whatever the server queued outside a request
    private function sweep(): void
    {
        $now = microtime(true);
        $head_timeout = (float) config('web.limits.head_timeout', 10);
        $idle = (float) config('web.session.idle', 300);

        foreach ($this->connections as $key => $connection) {
            if (is_null($connection->request()) && $now - $connection->opened_at > $head_timeout) {
                $connection->respond(408, 'text/plain', 'request timeout');
            }

            if ($connection->closed()) {
                unset($this->connections[$key]);
            }
        }

        foreach ($this->sessions as $id => $session) {
            if ($now - $session->last_seen > $idle) {
                $session->close();
                unset($this->sessions[$id]);

                continue;
            }

            $session->flushOutbox();
            $session->sweep();
        }
    }
}
