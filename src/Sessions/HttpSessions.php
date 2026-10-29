<?php

namespace SuperconductorMCP\SSE\Sessions;

use Fabricate\Mechanism\Session\Session;
use Fabricate\Mechanism\Session\SessionBag;
use Fabricate\Server\ServerRepository;
use Voyager\Contracts\IOPools\Loop;

// the open sessions, by id; each runs its own instance of the server it was opened on
final class HttpSessions
{
    /** @var array<string, HttpSession> */
    private array $sessions = [];

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Loop $event_loop,
    ) {}

    // a new session on the named server; an unknown name throws from the repository
    public function open(string $server): HttpSession
    {
        $this->sweep();

        $bag = new SessionBag();
        $bag->server_configuration = $this->servers->get($server);
        $bag->notifications = (array) config('sse.notifications', []);

        $session = new HttpSession(bin2hex(random_bytes(16)), $server, new Session($bag, $this->event_loop));

        return $this->sessions[$session->id] = $session;
    }

    // the session with this id on this server, or null when there is none (never opened, ended, or another server's)
    public function find(string $id, string $server): ?HttpSession
    {
        $this->sweep();
        $session = $this->sessions[$id] ?? null;

        if (is_null($session) || $session->server !== $server) {
            return null;
        }

        $session->last_seen = microtime(true);

        return $session;
    }

    public function forget(HttpSession $session): void
    {
        $session->closed = true;
        unset($this->sessions[$session->id]);
    }

    // idle sessions go whenever the registry is used: one with an open stream or a request running is not idle
    private function sweep(): void
    {
        $cutoff = microtime(true) - (float) config('sse.session.idle_timeout', 1800);

        foreach ($this->sessions as $session) {
            if ($session->last_seen < $cutoff && $session->streams === 0 && $session->bag()->in_flight === []) {
                $this->forget($session);
            }
        }
    }
}
