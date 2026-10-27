<?php

namespace SuperconductorMCP\SSE\Http;

// one HTTP/1.1 request and its response on a non-blocking socket: bytes to write, the head once it is in, the body as it arrives
final class Exchange
{
    private const int CHUNK = 65536;

    public string $out;

    public ?int $status = null;

    /** @var array<string, string> response headers, names lowercased */
    public array $headers = [];

    // the body gathered so far, for callers that want the whole thing
    public string $body = '';

    // the SSE parser, once the response turned out to be an event stream
    public ?EventParser $events = null;

    private string $in = '';

    private bool $chunked = false;

    // content-length bytes still owed; null when the body runs until the server closes
    private ?int $remaining = null;

    private bool $finished = false;

    /**
     * @param resource $socket
     * @param string $kind what the caller uses the exchange for; it is not read here
     * @param string $payload the request body, kept for a caller that has to send it again elsewhere
     */
    public function __construct(
        public readonly mixed $socket,
        string $request,
        public readonly string $kind = 'post',
        public readonly string $payload = '',
    ) {
        $this->out = $request;
    }

    // writes what the socket takes; false when the write itself failed
    public function flush(): bool
    {
        // a peer that is gone makes fwrite notice "Broken pipe"; the false return is the whole story here
        set_error_handler(static fn (): bool => true);

        try {
            $written = fwrite($this->socket, $this->out);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            return false;
        }

        $this->out = substr($this->out, $written);

        return true;
    }

    // reads what the socket has and returns the body bytes that arrived, dechunked; '' until the head is complete
    public function read(): string
    {
        while (($chunk = fread($this->socket, self::CHUNK)) !== false && $chunk !== '') {
            $this->in .= $chunk;
        }

        if (is_null($this->status) && ! $this->readHead()) {
            // a socket that closes before a head is a failed exchange
            $this->finished = feof($this->socket);

            return '';
        }

        $body = $this->chunked ? $this->dechunk() : $this->takeAll();
        $this->body .= $body;

        if (! is_null($this->remaining)) {
            $this->remaining -= strlen($body);
            $this->finished = $this->finished || $this->remaining <= 0;
        }

        $this->finished = $this->finished || feof($this->socket);

        return $body;
    }

    public function headed(): bool
    {
        return ! is_null($this->status);
    }

    // the whole response is in, or the server closed
    public function finished(): bool
    {
        return $this->finished;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->headers['content-type'] ?? '')[0]));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function readHead(): bool
    {
        $end = strpos($this->in, "\r\n\r\n");

        if ($end === false) {
            return false;
        }

        $lines = explode("\r\n", substr($this->in, 0, $end));
        $this->in = substr($this->in, $end + 4);
        $this->status = (int) (explode(' ', array_shift($lines) ?? '')[1] ?? 0);

        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $this->headers[strtolower(trim($name))] = trim($value);
        }

        $this->chunked = strtolower($this->headers['transfer-encoding'] ?? '') === 'chunked';

        if (! $this->chunked && isset($this->headers['content-length'])) {
            $this->remaining = (int) $this->headers['content-length'];
            $this->finished = $this->remaining === 0;
        }

        return true;
    }

    private function takeAll(): string
    {
        [$all, $this->in] = [$this->in, ''];

        return $all;
    }

    // chunked transfer encoding: size line, bytes, CRLF; a size of 0 ends the body
    private function dechunk(): string
    {
        $out = '';

        while (($at = strpos($this->in, "\r\n")) !== false) {
            $size = hexdec(trim(explode(';', substr($this->in, 0, $at))[0]));

            if (strlen($this->in) < $at + 2 + $size + 2) {
                break;
            }

            $out .= substr($this->in, $at + 2, $size);
            $this->in = substr($this->in, $at + 2 + $size + 2);

            if ($size === 0) {
                $this->finished = true;
                break;
            }
        }

        return $out;
    }
}
