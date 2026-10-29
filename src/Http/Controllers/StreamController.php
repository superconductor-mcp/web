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
 * messages, resource updates), open until the session ends. With Last-Event-ID it resumes the stream that
 * id names instead, a GET's or a POST's, replaying what came after it on that stream only. A client that
 * hangs up has this request cancelled; its stream and session stay for it to come back to.
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

        // an id naming a stream already ended (or never this session's) has nothing to replay: listen afresh
        [$stream, $after] = $session->resumable((string) $request->headers->get('Last-Event-ID'))
            ?? [$session->openStream(fn (): bool => $session->closed, listening: true), 0];

        return new StreamedResponse(function () use ($session, $stream, $after) {
            $session->streams++;
            $poll = $stream->listening ? $this->poll($session, $stream) : null;

            try {
                $session->follow($stream, $this->event_loop, $after);
            } finally {
                if (! is_null($poll)) {
                    // a registered timer stays named on the loop until forgotten
                    $poll->cancel();
                    $this->event_loop->forget("sse.poll.{$session->id}.{$stream->id}");
                }

                $session->streams--;
                $session->last_seen = microtime(true);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    // while a connection follows a listening stream, what the server queued outside any request moves into it
    private function poll(HttpSession $session, EventStream $stream): \Voyager\Contracts\IOPools\LoopResources\Timer
    {
        return $this->event_loop->every(
            (float) config('sse.session.poll_interval', 0.1),
            fn () => $session->session->flushOutbox($stream->push(...)),
            "sse.poll.{$session->id}.{$stream->id}",
        );
    }
}
