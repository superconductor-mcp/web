<?php

namespace SuperconductorMCP\SSE\Server;

// one HTTP request as it came off the socket: enough for an MCP endpoint, nothing for a web page
final readonly class Request
{
    /**
     * @param array<string, string> $headers names lowercased
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
