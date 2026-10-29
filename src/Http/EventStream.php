<?php

namespace SuperconductorMCP\SSE\Http;

use Closure;
use Voyager\Contracts\IOPools\Loop;

// JSON-RPC lines a session sends, written to a streamed response as server-sent events
final class EventStream
{
    /** @var list<string> */
    private array $events = [];

    public function push(string $json): void
    {
        $this->events[] = $json;
    }

    /** @return list<string> what was pushed and not yet written */
    public function pending(): array
    {
        return $this->events;
    }

    public function flush(): void
    {
        foreach ($this->events as $json) {
            echo "event: message\ndata: {$json}\n\n";
        }

        $this->events = [];
    }

    // write events as they are pushed until $done says the stream is finished, waiting on the loop in between
    public function pipeUntil(Loop $loop, Closure $done): void
    {
        while (true) {
            $this->flush();

            if ($done()) {
                $this->flush();

                return;
            }

            $loop->until(function () use ($done) {
                return $this->events !== [] || $done();
            });
        }
    }
}
