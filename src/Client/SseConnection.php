<?php

namespace SuperconductorMCP\SSE\Client;

use Fabricate\NutsAndBolts\LineDecoder;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use SuperconductorMCP\SSE\Http\Exchange;
use SuperconductorMCP\SSE\Http\EventParser;

// the client end of 2024-11-05 HTTP with SSE: one long GET carries the server's messages down, one POST per message goes up
class SseConnection extends HttpSockets
{
    private ?Exchange $stream = null;

    // where client messages go, once the server's endpoint event has said so
    private ?string $endpoint = null;

    /** @var list<string> lines handed to send() before the endpoint was known */
    private array $waiting = [];

    /** @var array<int, Exchange> POSTs in flight, keyed by socket id */
    private array $posts = [];

    private bool $open = false;

    private bool $released = false;

    public function open(): void
    {
        $this->parseUrl();
        $this->stream = $this->exchange('GET', $this->target(), ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache'], kind: 'stream');
        $this->open = true;

        $this->event_loop->resource(self::PREFIX.$this->client->name(), $this);
    }

    // a message leaves as its own POST; until the server has named the endpoint it waits
    public function send(string $line): void
    {
        if (! $this->open) {
            return;
        }

        if (is_null($this->endpoint)) {
            $this->waiting[] = $line;

            return;
        }

        $post = $this->exchange('POST', $this->endpoint, ['Content-Type' => 'application/json'], $line);
        $this->posts[(int) $post->socket] = $post;
    }

    public function close(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;
        $this->event_loop->forget(self::PREFIX.$this->client->name());
        $this->release();
    }

    public function closed(): bool
    {
        return $this->released || ! $this->open;
    }

    public function wakes(): array
    {
        if (! $this->open) {
            return [];
        }

        $wakes = [new Readable($this->stream->socket)];

        if ($this->stream->out !== '') {
            $wakes[] = new Writable($this->stream->socket);
        }

        foreach ($this->posts as $post) {
            $wakes[] = $post->out === '' ? new Readable($post->socket) : new Writable($post->socket);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        foreach ($fired as $wake) {
            if (! $this->open) {
                return;
            }

            $socket = $wake instanceof Readable || $wake instanceof Writable ? $wake->stream : null;

            if ($socket === $this->stream->socket) {
                $wake->kind() === WakeReason::WRITEABLE ? $this->flushStream() : $this->readStream();
            } elseif (isset($this->posts[(int) $socket])) {
                $wake->kind() === WakeReason::WRITEABLE ? $this->flushPost($this->posts[(int) $socket]) : $this->readPost($this->posts[(int) $socket]);
            }
        }
    }

    private function flushStream(): void
    {
        if (! $this->stream->flush()) {
            $this->ended('the event stream could not be written to.');
        }
    }

    private function readStream(): void
    {
        $was_headed = $this->stream->headed();
        $body = $this->stream->read();

        if (! $this->stream->headed()) {
            if ($this->stream->finished()) {
                $this->ended('the server closed the event stream.');
            }

            return;
        }

        // the GET's response head: anything but 200 text/event-stream ends the connection with the server's answer
        if (! $was_headed) {
            if ($this->stream->status !== 200) {
                $this->ended("the server answered {$this->stream->status} to the event stream request.");

                return;
            }

            if ($this->stream->contentType() !== 'text/event-stream') {
                $this->ended('the server did not answer with an event stream (content-type '.($this->stream->headers['content-type'] ?? 'missing').').');

                return;
            }

            $this->stream->events = new EventParser();
        }

        $messages = [];

        foreach ($this->stream->events->feed($body) as [$event, $data]) {
            // the endpoint event names where to POST; every other event carries one JSON-RPC message or a batch
            if ($event === 'endpoint') {
                $this->endpoint = $this->resolve($data);

                foreach (array_splice($this->waiting, 0) as $line) {
                    $this->send($line);
                }

                continue;
            }

            array_push($messages, ...LineDecoder::decode($data));
        }

        if ($messages !== []) {
            $this->client->receive($messages);
        }

        if ($this->open && $this->stream->finished()) {
            $this->ended('the server closed the event stream.');
        }
    }

    // the endpoint may be absolute or relative to the stream's url
    private function resolve(string $endpoint): string
    {
        $parsed = parse_url($endpoint);

        if (isset($parsed['host'])) {
            return ($parsed['path'] ?? '/').(isset($parsed['query']) ? '?'.$parsed['query'] : '');
        }

        if (str_starts_with($endpoint, '/')) {
            return $endpoint;
        }

        return rtrim(dirname($this->url['path'] ?? '/'), '/').'/'.$endpoint;
    }

    private function flushPost(Exchange $post): void
    {
        if (! $post->flush()) {
            $this->ended('a message could not be posted to the server.');
        }
    }

    // the POST's answer: a 2xx is all the server owes; its responses arrive on the stream
    private function readPost(Exchange $post): void
    {
        $post->read();

        if (! $post->headed()) {
            if ($post->finished()) {
                $this->ended('the server closed a POST without answering.');
            }

            return;
        }

        $post->close();
        unset($this->posts[(int) $post->socket]);

        if ($post->status < 200 || $post->status >= 300) {
            $this->ended("the server answered {$post->status} to a posted message.");
        }
    }

    // the server went away, or refused something it must accept
    private function ended(string $why): void
    {
        $this->close();
        $this->client->connectionClosed($why);
    }

    private function release(): void
    {
        $this->released = true;

        foreach ($this->posts as $post) {
            $post->close();
        }

        $this->stream?->close();
        $this->posts = [];
        $this->waiting = [];
    }
}
