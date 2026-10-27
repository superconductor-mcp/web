<?php

namespace SuperconductorMCP\SSE\Server;

// one accepted socket: reads a request, writes its response (a document or an event stream), then closes
final class Incoming
{
    private const int CHUNK = 65536;

    private const array REASONS = [200 => 'OK', 202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 408 => 'Request Timeout', 413 => 'Content Too Large', 500 => 'Internal Server Error'];

    public readonly float $opened_at;

    private string $in = '';

    private string $out = '';

    private ?Request $request = null;

    private ?string $method = null;

    private ?string $path = null;

    private array $headers = [];

    private int $body_length = 0;

    // a response has been started; nothing more is read
    private bool $responding = false;

    // an event stream: stays open until end()
    private bool $streaming = false;

    private bool $closed = false;

    /** @param resource $socket */
    public function __construct(
        public readonly mixed $socket,
        private readonly int $head_limit,
        private readonly int $body_limit,
    ) {
        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        $this->opened_at = microtime(true);
    }

    // reads what the socket has; returns the request once it is complete, or null to keep waiting. A bad request is answered here.
    public function read(): ?Request
    {
        if ($this->responding) {
            // the client has nothing more to say; drain so a close shows up
            while (($chunk = fread($this->socket, self::CHUNK)) !== false && $chunk !== '') {
            }

            if (feof($this->socket) && ! $this->streaming) {
                $this->close();
            }

            return null;
        }

        while (($chunk = fread($this->socket, self::CHUNK)) !== false && $chunk !== '') {
            $this->in .= $chunk;
        }

        if (is_null($this->method)) {
            $end = strpos($this->in, "\r\n\r\n");

            if ($end === false) {
                if (strlen($this->in) > $this->head_limit) {
                    $this->respond(400, 'text/plain', 'request head too large');
                } elseif (feof($this->socket)) {
                    $this->close();
                }

                return null;
            }

            if (! $this->parseHead(substr($this->in, 0, $end))) {
                $this->respond(400, 'text/plain', 'malformed request');

                return null;
            }

            $this->in = substr($this->in, $end + 4);

            if ($this->body_length > $this->body_limit) {
                $this->respond(413, 'text/plain', 'request body too large');

                return null;
            }
        }

        if (strlen($this->in) < $this->body_length) {
            if (feof($this->socket)) {
                $this->close();
            }

            return null;
        }

        $this->request = new Request($this->method, $this->path, $this->headers, substr($this->in, 0, $this->body_length));
        $this->in = '';

        return $this->request;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    // a whole document; the connection closes once it is written
    public function respond(int $status, string $type, string $body, array $headers = []): void
    {
        if ($this->responding) {
            return;
        }

        $this->responding = true;
        $this->out .= $this->head($status, ['Content-Type' => $type, 'Content-Length' => (string) strlen($body), ...$headers]).$body;
        $this->flush();
    }

    // an event stream: the head goes out now, events follow, end() closes
    public function stream(array $headers = []): void
    {
        if ($this->responding) {
            return;
        }

        $this->responding = true;
        $this->streaming = true;
        $this->out .= $this->head(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', ...$headers]);
        $this->flush();
    }

    public function event(string $data, ?int $id = null): void
    {
        if (! $this->streaming || $this->closed) {
            return;
        }

        $this->out .= (is_null($id) ? '' : "id: {$id}\n")."event: message\ndata: {$data}\n\n";
        $this->flush();
    }

    // the stream is over: whatever is still buffered goes out, then the socket closes
    public function end(): void
    {
        $this->streaming = false;

        if ($this->out === '') {
            $this->close();
        }
    }

    public function streaming(): bool
    {
        return $this->streaming && ! $this->closed;
    }

    // the request has been read and nothing is waiting to be written
    public function idle(): bool
    {
        return $this->responding && $this->out === '';
    }

    public function wantsWrite(): bool
    {
        return $this->out !== '' && ! $this->closed;
    }

    public function flush(): void
    {
        if ($this->out === '' || $this->closed) {
            return;
        }

        set_error_handler(static fn (): bool => true);

        try {
            $written = fwrite($this->socket, $this->out);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            $this->close();

            return;
        }

        $this->out = substr($this->out, $written);

        if ($this->out === '' && $this->responding && ! $this->streaming) {
            $this->close();
        }
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;

            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    private function parseHead(string $head): bool
    {
        $lines = explode("\r\n", $head);
        $parts = explode(' ', array_shift($lines));

        if (count($parts) !== 3 || ! str_starts_with($parts[2], 'HTTP/1.')) {
            return false;
        }

        $this->method = strtoupper($parts[0]);
        $this->path = explode('?', $parts[1], 2)[0];

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                return false;
            }

            [$name, $value] = explode(':', $line, 2);
            $this->headers[strtolower(trim($name))] = trim($value);
        }

        // only sized bodies: a chunked request body has no place on an MCP endpoint
        if (isset($this->headers['transfer-encoding'])) {
            return false;
        }

        $this->body_length = (int) ($this->headers['content-length'] ?? 0);

        return $this->body_length >= 0;
    }

    private function head(int $status, array $headers): string
    {
        $lines = ["HTTP/1.1 {$status} ".(self::REASONS[$status] ?? 'Unknown'), 'Connection: close'];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines)."\r\n\r\n";
    }
}
