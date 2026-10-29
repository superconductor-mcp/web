<?php

namespace SuperconductorMCP\SSE\Http\Controllers;

use Mariner\Http\Request;
use SuperconductorMCP\SSE\Http\EventStream;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Sessions\HttpSession;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Voyager\Contracts\IOPools\Loop;

/**
 * GET: a server-sent event stream for what the server sends outside any request (list changes, log
 * messages, resource updates). It stays open until the session ends or the client hangs up; a hang-up
 * cancels it, and the session stays for the client to open another.
 */
class StreamController
{
    public function __construct(private readonly Loop $event_loop) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        if (! str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
            return ResolveSession::refuse(406, 'This stream is text/event-stream: the Accept header must allow it.');
        }

        /** @var HttpSession $session */
        $session = $request->attributes->get(ResolveSession::ATTRIBUTE);

        return new StreamedResponse(function () use ($session) {
            $stream = new EventStream();
            $session->streams++;

            try {
                while (! $session->closed) {
                    $session->session->flushOutbox($stream->push(...));
                    $stream->flush();
                    $this->sleep((float) config('sse.session.poll_interval', 0.1));
                }
            } finally {
                $session->streams--;
                $session->last_seen = microtime(true);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    private function sleep(float $seconds): void
    {
        $woken = $this->event_loop->promise();
        $this->event_loop->at($seconds, fn () => $woken->resolve(null));
        $this->event_loop->await($woken);
    }
}
