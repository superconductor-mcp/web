<?php

namespace SuperconductorMCP\SSE\Http\Controllers;

use Fabricate\Mechanism\Session\Lifecycle\IdleState;
use Fabricate\NutsAndBolts\LineDecoder;
use Fabricate\NutsAndBolts\Messages\Errors\InvalidRequest;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\Server\ServerRepository;
use Mariner\Http\Request;
use Mariner\Http\Response;
use SuperconductorMCP\SSE\Http\Middleware\Authenticate;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Sessions\HttpSessions;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Voyager\Contracts\IOPools\Loop;

/**
 * POST: one JSON-RPC message, or a batch below 2025-06-18. Requests are answered on a server-sent event
 * stream that also carries what the server sends while they run (progress, log messages, elicitation)
 * and ends once every one of them is answered or cancelled. Only notifications and responses: 202.
 */
class MessagesController
{
    public function __construct(
        private readonly HttpSessions $sessions,
        private readonly ServerRepository $servers,
        private readonly Loop $event_loop,
    ) {}

    public function __invoke(Request $request, string $server): SymfonyResponse
    {
        $body = $request->getContent();
        $messages = LineDecoder::decode($body);
        $session = $request->attributes->get(ResolveSession::ATTRIBUTE);
        $opened = is_null($session);
        $ids = [];

        foreach ($messages as $message) {
            if ($message instanceof MCPRequest) {
                $ids[$message->id] = true;
            }
        }

        if ($ids !== [] && ! str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
            return ResolveSession::refuse(406, 'Requests are answered as text/event-stream: the Accept header must allow it.');
        }

        if ($opened) {
            if (count($messages) !== 1 || ! $messages[0] instanceof InitializeRequest) {
                return ResolveSession::refuse(400, 'An Mcp-Session-Id header is required, except to initialize.');
            }

            if (! $this->servers->has($server)) {
                return ResolveSession::refuse(404, "No MCP server [{$server}].");
            }

            $session = $this->sessions->open($server);
            $session->owner = $request->attributes->get(Authenticate::USER)?->getAuthIdentifier();
        } elseif (LineDecoder::isBatch($body) && ! $session->bag()->takesBatches()) {
            return self::json((new InvalidRequest(null, "Batches are not supported in protocol {$session->bag()->protocol_version}."))->toJson(), 400);
        }

        $bag = $session->bag();
        // a POST stream is done once every request it carried is answered or cancelled
        $stream = $session->openStream(fn (): bool => array_intersect_key($ids, $bag->in_flight) === []);
        $session->bindServer();

        foreach ($messages as $message) {
            if (! $session->session->handle($message, $stream->push(...))) {
                $stream->push((new InvalidRequest(null, 'The server could not handle this message.'))->toJson());
            }
        }

        // a refused initialize leaves no session behind
        if ($opened && $session->bag()->state instanceof IdleState) {
            $this->sessions->forget($session);
            $opened = false;
        }

        if ($ids === []) {
            unset($session->event_streams[$stream->id]);
            $errors = $stream->pending();

            return $errors === [] ? new Response('', 202) : self::json(count($errors) === 1 ? $errors[0] : '['.implode(',', $errors).']', 400);
        }

        $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'];

        if ($opened) {
            $headers['Mcp-Session-Id'] = $session->id;
        }

        // a client that loses this stream resumes it with a GET and Last-Event-ID; the requests run on meanwhile
        return new StreamedResponse(fn () => $session->follow($stream, $this->event_loop), 200, $headers);
    }

    private static function json(string $json, int $status): Response
    {
        return new Response($json, $status, ['Content-Type' => 'application/json']);
    }
}
