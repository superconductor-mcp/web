<?php

namespace SuperconductorMCP\SSE\Client;

use Throwable;
use Fabricate\Client\ClientStatus;
use Fabricate\NutsAndBolts\LineDecoder;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use SuperconductorMCP\SSE\Http\Exchange;
use SuperconductorMCP\SSE\Http\EventParser;

// the client end of 2025-03-26 Streamable HTTP: every message is its own POST to the one MCP endpoint, answered with JSON
// or an event stream; a GET opens a stream for what the server sends on its own; the session id rides on every request
class HttpConnection extends HttpSockets
{
    private const string ACCEPT = 'application/json, text/event-stream';

    // how long a close waits for its DELETE to go out before releasing anyway
    private const float CLOSE_GRACE = 2.0;

    /** @var array<int, Exchange> every request in flight, keyed by socket id: posts, the listening GET, the closing DELETE */
    private array $exchanges = [];

    private ?Exchange $listener = null;

    private ?string $session = null;

    // the id of the last event the listening stream delivered, for a resume
    private ?string $last_event_id = null;

    // the server answered the GET with 405, or something else that is not a stream: it has no stream to offer
    private bool $listen_refused = false;

    private int $silent_reconnects = 0;

    private ?Timer $reconnect = null;

    private ?Timer $close_deadline = null;

    private bool $open = false;

    private bool $closing = false;

    private bool $released = false;

    // the 2024-11-05 transport, once the endpoint turned out to speak it
    private ?SseConnection $fallback = null;

    public function open(): void
    {
        $this->parseUrl();
        $this->open = true;

        $this->event_loop->resource(self::PREFIX.$this->client->name(), $this);
    }

    public function send(string $line): void
    {
        if ($this->fallback) {
            $this->fallback->send($line);

            return;
        }

        if (! $this->open || $this->closing) {
            return;
        }

        $this->post($line);
    }

    // a close tells a stateful server its session is over, then releases; a server that never answers the DELETE is not waited on
    public function close(): void
    {
        if ($this->fallback) {
            $this->fallback->close();

            return;
        }

        if (! $this->open || $this->closing) {
            return;
        }

        $this->closing = true;
        $this->reconnect?->cancel();

        foreach ($this->exchanges as $exchange) {
            $exchange->close();
        }

        $this->exchanges = [];
        $this->listener = null;

        if (is_null($this->session)) {
            $this->finish();

            return;
        }

        try {
            $delete = $this->exchange('DELETE', $this->target(), $this->sessionHeaders(), kind: 'delete');
        } catch (Throwable) {
            $this->finish();

            return;
        }

        $this->exchanges[(int) $delete->socket] = $delete;
        $this->close_deadline = $this->event_loop->at(self::CLOSE_GRACE, function (): void {
            $this->finish();
        });
    }

    public function closed(): bool
    {
        return $this->fallback ? $this->fallback->closed() : ($this->released || ! $this->open);
    }

    public function wakes(): array
    {
        if (! $this->open || $this->released) {
            return [];
        }

        $wakes = [];

        foreach ($this->exchanges as $exchange) {
            $wakes[] = $exchange->out === '' ? new Readable($exchange->socket) : new Writable($exchange->socket);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        foreach ($fired as $wake) {
            if (! $this->open || $this->released) {
                return;
            }

            $socket = $wake instanceof Readable || $wake instanceof Writable ? $wake->stream : null;
            $exchange = $this->exchanges[(int) $socket] ?? null;

            if (is_null($exchange)) {
                continue;
            }

            $wake->kind() === WakeReason::WRITEABLE ? $this->flush($exchange) : $this->read($exchange);
        }
    }

    private function post(string $line): void
    {
        try {
            $post = $this->exchange('POST', $this->target(), ['Accept' => self::ACCEPT, 'Content-Type' => 'application/json', ...$this->sessionHeaders()], $line);
        } catch (Throwable $e) {
            $this->ended($e->getMessage());

            return;
        }

        $this->exchanges[(int) $post->socket] = $post;
    }

    // the GET that lets the server speak first; opened once the handshake is done, reopened where it left off when the server drops it
    private function listen(): void
    {
        if (! $this->open || $this->closing || $this->listen_refused || $this->listener) {
            return;
        }

        $headers = ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache', ...$this->sessionHeaders()];

        if (! is_null($this->last_event_id)) {
            $headers['Last-Event-ID'] = $this->last_event_id;
        }

        try {
            $this->listener = $this->exchange('GET', $this->target(), $headers, kind: 'listen');
        } catch (Throwable $e) {
            $this->ended($e->getMessage());

            return;
        }

        $this->exchanges[(int) $this->listener->socket] = $this->listener;
    }

    // the session id once the server gave one, and from 2025-06-18 the negotiated version on every request after initialize
    private function sessionHeaders(): array
    {
        $version = $this->client->protocolVersion();

        return [
            ...(is_null($this->session) ? [] : ['Mcp-Session-Id' => $this->session]),
            ...(! is_null($version) && $version >= '2025-06-18' ? ['MCP-Protocol-Version' => $version] : []),
        ];
    }

    private function flush(Exchange $exchange): void
    {
        if ($exchange->flush()) {
            return;
        }

        if ($exchange->kind === 'delete') {
            $this->finish();

            return;
        }

        $this->ended($exchange->kind === 'listen' ? 'the event stream could not be written to.' : 'a message could not be posted to the server.');
    }

    private function read(Exchange $exchange): void
    {
        $was_headed = $exchange->headed();
        $body = $exchange->read();

        if ($exchange->kind === 'delete') {
            if ($exchange->headed() || $exchange->finished()) {
                $this->finish();
            }

            return;
        }

        if (! $exchange->headed()) {
            if ($exchange->finished()) {
                $this->ended($exchange->kind === 'listen' ? 'the server closed the event stream.' : 'the server closed a POST without answering.');
            }

            return;
        }

        if (! $was_headed && ! $this->headed($exchange)) {
            return;
        }

        if ($exchange->events) {
            $this->events($exchange, $body);
        }

        if (! $exchange->finished()) {
            return;
        }

        $this->drop($exchange);

        if ($exchange->kind === 'post' && is_null($exchange->events) && trim($exchange->body) !== '') {
            $this->deliver(LineDecoder::decode(trim($exchange->body)));
        }

        if ($exchange->kind === 'listen') {
            $this->listener = null;
            $this->reconnectLater();
        }
    }

    // the response head just arrived: decide what the body is, or end on what the server said. False when the exchange is over.
    private function headed(Exchange $exchange): bool
    {
        $status = $exchange->status;

        if (isset($exchange->headers['mcp-session-id']) && is_null($this->session)) {
            $this->session = $exchange->headers['mcp-session-id'];
        }

        if ($exchange->kind === 'listen') {
            if ($status === 200 && $exchange->contentType() === 'text/event-stream') {
                $exchange->events = new EventParser();

                return true;
            }

            $this->drop($exchange);
            $this->listener = null;

            // 404 on a session means the server ended it; anything else means no stream here
            if ($status === 404 && ! is_null($this->session)) {
                $this->ended('the server ended the session.');
            } else {
                $this->listen_refused = true;
            }

            return false;
        }

        if ($status >= 200 && $status < 300) {
            if ($exchange->contentType() === 'text/event-stream') {
                $exchange->events = new EventParser();
            }

            return true;
        }

        $this->drop($exchange);

        // 2025-03-26 backwards compatibility: a 4xx to the initialize POST means the endpoint may speak 2024-11-05
        if ($status >= 400 && $status < 500 && is_null($this->session) && $this->client->status() === ClientStatus::Connecting && ($this->definition['fallback'] ?? true)) {
            $this->fallBack($exchange);

            return false;
        }

        if ($status === 404 && ! is_null($this->session)) {
            $this->ended('the server ended the session.');
        } else {
            $this->ended("the server answered {$status} to a posted message.");
        }

        return false;
    }

    // body bytes of an event stream: each event's data is one JSON-RPC message or a batch
    private function events(Exchange $exchange, string $body): void
    {
        $messages = [];

        foreach ($exchange->events->feed($body) as [, $data]) {
            array_push($messages, ...LineDecoder::decode($data));
        }

        if ($exchange->kind === 'listen' && ! is_null($exchange->events->last_id)) {
            $this->last_event_id = $exchange->events->last_id;
        }

        if ($messages !== []) {
            $this->silent_reconnects = 0;
            $this->deliver($messages);
        }
    }

    private function deliver(array $messages): void
    {
        $this->client->receive($messages);

        // the handshake just finished: open the stream the server may speak on
        if ($this->client->status() === ClientStatus::Connected && is_null($this->listener)) {
            $this->listen();
        }
    }

    // the server dropped the listening stream: come back for the rest, unless it keeps dropping it without saying anything
    private function reconnectLater(): void
    {
        if ($this->closing || ! $this->open) {
            return;
        }

        if (++$this->silent_reconnects > (int) config('mcp.client.stream_reconnects', 5)) {
            $this->listen_refused = true;

            return;
        }

        $this->reconnect = $this->event_loop->at(1.0, function (): void {
            $this->listen();
        });
    }

    // the POST that was the initialize got refused: hand the whole client to the 2024-11-05 transport on the same url
    private function fallBack(Exchange $refused): void
    {
        // the fallback registers itself under the client's name; this connection is a shell from here on
        $this->event_loop->forget(self::PREFIX.$this->client->name());
        $this->fallback = new SseConnection($this->definition, $this->event_loop, $this->client);

        try {
            $this->fallback->open();
        } catch (Throwable $e) {
            $this->fallback = null;
            $this->ended("the server answered {$refused->status} to initialize, and ".$e->getMessage());

            return;
        }

        foreach ($this->exchanges as $exchange) {
            $exchange->close();
        }

        $this->exchanges = [];
        $this->fallback->send($refused->payload);
    }

    private function drop(Exchange $exchange): void
    {
        $exchange->close();
        unset($this->exchanges[(int) $exchange->socket]);
    }

    // the server went away, or refused something it must accept
    private function ended(string $why): void
    {
        $this->closing = true;
        $this->finish();
        $this->client->connectionClosed($why);
    }

    private function finish(): void
    {
        if ($this->released) {
            return;
        }

        $this->open = false;
        $this->released = true;
        $this->reconnect?->cancel();
        $this->close_deadline?->cancel();
        $this->event_loop->forget(self::PREFIX.$this->client->name());

        foreach ($this->exchanges as $exchange) {
            $exchange->close();
        }

        $this->exchanges = [];
        $this->listener = null;
    }
}
