<?php

namespace SuperconductorMCP\SSE\Client;

use RuntimeException;
use Fabricate\Client\MCPClient;
use Fabricate\Contracts\Client\Connection;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Resources\WakeSource;
use SuperconductorMCP\SSE\Http\Exchange;

// what both HTTP transports share: the entry's url, async sockets to its host, and HTTP/1.1 requests carrying the entry's headers
abstract class HttpSockets extends WakeSource implements Connection
{
    public const string PREFIX = 'mcp.client.';

    protected array $url = [];

    public function __construct(
        protected readonly array $definition,
        protected readonly Loop $event_loop,
        protected readonly MCPClient $client,
    ) {}

    public function describe(): string
    {
        return (string) ($this->definition['url'] ?? '');
    }

    // the entry's url, checked once at open
    protected function parseUrl(): void
    {
        $url = parse_url((string) ($this->definition['url'] ?? ''));

        if (! is_array($url) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])) {
            throw new RuntimeException('could not start: the entry needs an http or https url.');
        }

        $this->url = $url;
    }

    protected function target(): string
    {
        return ($this->url['path'] ?? '/').(isset($this->url['query']) ? '?'.$this->url['query'] : '');
    }

    // an async connect to the url's host carrying one request; the bytes go out once the socket turns writable
    protected function exchange(string $method, string $target, array $headers, string $body = '', string $kind = 'post'): Exchange
    {
        $tls = $this->url['scheme'] === 'https';
        $port = $this->url['port'] ?? ($tls ? 443 : 80);
        $context = stream_context_create(['ssl' => ['peer_name' => $this->url['host']]]);

        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp').'://'.$this->url['host'].':'.$port, $errno, $error, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new RuntimeException("could not start: {$this->url['host']}:{$port} refused the connection ({$error}).");
        }

        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);

        return new Exchange($socket, $this->request($method, $target, $headers, $body), $kind, $body);
    }

    // one HTTP/1.1 request, with the entry's own headers (an Authorization, say) on every one; each one closes when answered
    private function request(string $method, string $target, array $headers, string $body): string
    {
        $host = $this->url['host'].(isset($this->url['port']) ? ':'.$this->url['port'] : '');
        $headers = ['Host' => $host, 'Connection' => 'close', ...$headers, ...($this->definition['headers'] ?? [])];

        if ($body !== '' || $method === 'POST') {
            $headers['Content-Length'] = (string) strlen($body);
        }

        $lines = ["{$method} {$target} HTTP/1.1"];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines)."\r\n\r\n".$body;
    }
}
