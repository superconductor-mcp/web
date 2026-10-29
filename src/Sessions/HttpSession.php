<?php

namespace SuperconductorMCP\SSE\Sessions;

use Fabricate\Contracts\Server\MCPServer;
use Fabricate\Mechanism\Session\Session;
use Fabricate\Mechanism\Session\SessionBag;
use SuperconductorMCP\SSE\Http\EventStream;

// one client's session with one server over HTTP, named by its Mcp-Session-Id
final class HttpSession
{
    public float $last_seen;

    // GET streams open on this session; a session with one open is never idle
    public int $streams = 0;

    // the identifier of the user who opened it, when the endpoint needs a token; nobody else may use it
    public mixed $owner = null;

    // ended by DELETE or the idle timeout; open streams end on their next look
    public bool $closed = false;

    /** @var array<string, EventStream> streams a client may still resume, by id: kept until one is written to its end */
    public array $event_streams = [];

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

    /** @param \Closure(): bool $done */
    public function openStream(\Closure $done, bool $listening = false): EventStream
    {
        $stream = new EventStream($done, (int) config('sse.session.replay_events', 100), $listening);

        return $this->event_streams[$stream->id] = $stream;
    }

    // the stream a Last-Event-ID names, with the seq to resume after
    /** @return array{0: EventStream, 1: int}|null */
    public function resumable(string $last_event_id): ?array
    {
        foreach ($this->event_streams as $stream) {
            if (! is_null($seq = $stream->seqOf($last_event_id))) {
                return [$stream, $seq];
            }
        }

        return null;
    }

    // 2025-11-25: a stream opens with an id and no data, which older clients may not parse
    public function primes(): bool
    {
        return ($this->bag()->protocol_version ?? '') >= '2025-11-25';
    }

    // follow $stream to its end on the current connection, then let it go
    public function follow(EventStream $stream, \Voyager\Contracts\IOPools\Loop $loop, int $after = 0): void
    {
        // only a stream's first connection is primed; a resume already has an id to come back with
        if ($stream->follow($loop, $after, $after === 0 && $this->primes())) {
            unset($this->event_streams[$stream->id]);
        }
    }

    // tools that ask for their server get this session's instance; a handler is built before it first waits
    public function bindServer(): void
    {
        $server = $this->bag()->server_configuration;
        app()->registerInstance($server::class, $server);
        app()->registerInstance(MCPServer::class, $server);
    }
}
