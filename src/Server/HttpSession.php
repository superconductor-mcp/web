<?php

namespace SuperconductorMCP\SSE\Server;

use Voyager\Contracts\IOPools\Loop;
use Fabricate\Mechanism\ProcedureRouter;
use Fabricate\Mechanism\Session\Session;
use Fabricate\Mechanism\Session\SessionBag;
use Fabricate\NutsAndBolts\LineDecoder;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\Server\MCPServer;

/**
 * One client's session over Streamable HTTP: the shared Session logic, plus where each line goes. A POST's own
 * replies go back on that POST (as a document, or as events when a procedure runs async); everything the server
 * says on its own goes to the listening GET stream, kept in a ring so a dropped stream can resume.
 */
final class HttpSession
{
    public readonly SessionBag $bag;

    public float $last_seen;

    private readonly Session $session;

    private ?Incoming $listener = null;

    /** @var array<int, string> event id → line, the newest `replay` of them */
    private array $ring = [];

    private int $next_event_id = 1;

    // the highest event id written to a live stream
    private int $delivered = 0;

    /** @var array<int, array{0: Incoming, 1: array<string|int, true>}> POSTs answering as streams, with the request ids they owe */
    private array $streams = [];

    public function __construct(
        public readonly string $id,
        MCPServer $server,
        private readonly Loop $event_loop,
        private readonly ProcedureRouter $router,
        private readonly int $replay,
    ) {
        $this->bag = new SessionBag();
        $this->bag->server_configuration = $server;
        $this->bag->put('notifications', config('web.notifications', []));
        $this->session = new Session($this->bag, $event_loop, $router);
        $this->last_seen = microtime(true);
    }

    // a POST: one message or a batch. Requests get answered on this connection; notifications and responses get a 202.
    public function post(Incoming $incoming, string $body, array $headers = []): void
    {
        $this->last_seen = microtime(true);

        // 2025-06-18 removed batches; a session on it gets one refused whole
        if (LineDecoder::isBatch($body) && ! $this->bag->takesBatches()) {
            $incoming->respond(400, 'text/plain', "batches are not supported in protocol {$this->bag->protocol_version}", $headers);

            return;
        }

        $messages = LineDecoder::decode($body);
        $requests = array_filter($messages, fn (mixed $m): bool => $m instanceof MCPRequest);

        if ($requests === []) {
            foreach ($messages as $message) {
                $this->session->handle($message, fn (string $line) => $this->push($line));
            }

            $incoming->respond(202, 'text/plain', '', $headers);

            return;
        }

        // a procedure that waits on the loop answers over an event stream, with its progress ahead of the reply
        $async = array_filter($requests, fn (MCPRequest $r): bool => $this->router->runsAsync($r));

        if ($async !== []) {
            $incoming->stream($headers);
            $owed = [];

            foreach ($requests as $request) {
                $owed[$request->id] = true;
            }

            // the stream closes once nothing it owes is in flight: checked after the sync part here, and by the tick
            // for the fiber's reply, never inside $send, so what the server queues right after a reply still gets out
            $this->streams[(int) $incoming->socket] = [$incoming, $owed];
            $send = fn (string $line) => $incoming->event($line);

            foreach ($messages as $message) {
                $this->session->handle($message, $send);
            }

            $this->settle($incoming);

            return;
        }

        // every procedure answers now: the replies are the document, anything else the server said goes to the stream ring
        $replies = [];
        $send = function (string $line) use (&$replies): void {
            $decoded = json_decode($line, true);
            isset($decoded['method']) ? $this->push($line) : $replies[] = $line;
        };

        foreach ($messages as $message) {
            $this->session->handle($message, $send);
        }

        $document = count($replies) === 1 ? $replies[0] : '['.implode(',', $replies).']';
        $incoming->respond(200, 'application/json', $document, $headers);
    }

    // a GET: the stream the server speaks on by itself, resumed from the last event the client saw
    public function listen(Incoming $incoming, ?string $last_event_id): void
    {
        $this->last_seen = microtime(true);
        $this->listener?->end();
        $this->listener = $incoming;
        $incoming->stream();

        $after = is_null($last_event_id) ? $this->delivered : (int) $last_event_id;

        foreach ($this->ring as $id => $line) {
            if ($id > $after) {
                $incoming->event($line, $id);
                $this->delivered = max($this->delivered, $id);
            }
        }
    }

    // what the server says on its own: list_changed, log messages, resource updates
    public function push(string $line): void
    {
        $id = $this->next_event_id++;
        $this->ring[$id] = $line;

        while (count($this->ring) > $this->replay) {
            unset($this->ring[array_key_first($this->ring)]);
        }

        if ($this->listener?->streaming()) {
            $this->listener->event($line, $id);
            $this->delivered = $id;
        }
    }

    public function flushOutbox(): void
    {
        $this->session->flushOutbox(fn (string $line) => $this->push($line));
    }

    // housekeeping each tick: a stream whose last owed request finished without a reply (cancelled) ends; a dropped listener is forgotten
    public function sweep(): void
    {
        foreach ($this->streams as $key => [$incoming]) {
            if ($incoming->closed()) {
                unset($this->streams[$key]);
            } else {
                $this->settle($incoming);
            }
        }

        if ($this->listener?->closed()) {
            $this->listener = null;
        }
    }

    public function close(): void
    {
        $this->listener?->end();
        $this->listener = null;

        foreach ($this->streams as [$incoming]) {
            $incoming->end();
        }

        $this->streams = [];
    }

    // a POST stream closes once none of its requests is still in flight
    private function settle(Incoming $incoming): void
    {
        $key = (int) $incoming->socket;

        if (! isset($this->streams[$key])) {
            return;
        }

        foreach (array_keys($this->streams[$key][1]) as $id) {
            if (isset($this->bag->in_flight[$id])) {
                return;
            }
        }

        unset($this->streams[$key]);
        $incoming->end();
    }
}
