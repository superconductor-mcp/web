<?php

namespace SuperconductorMCP\SSE\Http;

use Closure;
use Voyager\Contracts\IOPools\Loop;

/**
 * One server-sent event stream of a session: the JSON-RPC lines the session sends on it, numbered so a client
 * that lost the connection can resume with Last-Event-ID. Event ids are `<stream>:<seq>`, unique in the session
 * and naming their stream, so a resume replays only what this stream missed. A stream outlives a dropped
 * connection: what the session pushes meanwhile waits here for the next one to follow it.
 */
final class EventStream
{
    public readonly string $id;

    private int $seq = 0;

    /** @var array<int, string> seq => JSON line, the last $keep written ones kept for a replay, every unwritten one */
    private array $events = [];

    // the highest seq a connection has had written to it
    private int $written = 0;

    // bumped by each connection that follows the stream, so an older one still following stops
    private int $follower = 0;

    /**
     * @param Closure(): bool $done true once the stream has nothing more to carry
     */
    public function __construct(
        private readonly Closure $done,
        private readonly int $keep = 100,
        // a GET's own stream, fed from the server's queue, rather than a POST's
        public readonly bool $listening = false,
    ) {
        $this->id = bin2hex(random_bytes(8));
    }

    public function push(string $json): void
    {
        $this->events[++$this->seq] = $json;
    }

    /** @return list<string> pushed and never written */
    public function pending(): array
    {
        return array_values(array_filter($this->events, fn (int $seq): bool => $seq > $this->written, ARRAY_FILTER_USE_KEY));
    }

    public function done(): bool
    {
        return ($this->done)();
    }

    // the seq a Last-Event-ID of this stream names, or null when it names another stream or nothing
    public function seqOf(string $last_event_id): ?int
    {
        [$stream, $seq] = array_pad(explode(':', $last_event_id, 2), 2, '');

        return $stream === $this->id && ctype_digit($seq) ? (int) $seq : null;
    }

    /**
     * Write what came after $after, then what is pushed as it is, until the stream is done. $prime opens with an
     * event that has an id and no data (2025-11-25), so the client can resume a stream that has not sent anything yet.
     * Returning means the connection took everything; a client that hangs up meanwhile has the request cancelled
     * by the server, which throws out of the wait here and leaves the stream for a resume.
     *
     * @return bool true when the stream ended here; false when a newer connection took it over
     */
    public function follow(Loop $loop, int $after = 0, bool $prime = false): bool
    {
        $me = ++$this->follower;

        if ($prime) {
            echo "id: {$this->id}:{$after}\ndata: \n\n";
        }

        $this->written = min($after, $this->seq);

        while ($me === $this->follower) {
            $this->write();

            if ($this->done()) {
                $this->write();

                return true;
            }

            $loop->until(fn (): bool => $this->seq > $this->written || $this->done() || $me !== $this->follower);
        }

        return false;
    }

    private function write(): void
    {
        foreach ($this->events as $seq => $json) {
            if ($seq > $this->written) {
                echo "id: {$this->id}:{$seq}\nevent: message\ndata: {$json}\n\n";
                $this->written = $seq;
            }
        }

        // a replay reaches back at most $keep written events
        foreach (array_keys($this->events) as $seq) {
            if ($seq > $this->written - $this->keep) {
                break;
            }

            unset($this->events[$seq]);
        }
    }
}
