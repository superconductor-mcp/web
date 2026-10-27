<?php

namespace SuperconductorMCP\SSE\Runner\Sketches;

use Voyager\Contracts\Sketches\SketchLoopResult;
use Voyager\Contracts\Sketches\Attributes\Sketch;
use Fabricate\Core\Runner\Sketches\StartMCPServer;
use SuperconductorMCP\SSE\Server\McpHttpServer;

// `rocket mcp:http <server>`: the local HTTP server from the command line, for development and for harness configs
#[Sketch('mcp:http {server : The name of the MCP server to serve} {--port= : Port on 127.0.0.1; web.port when omitted} {--token= : Bearer the client must send; web.token when omitted, minted when that is empty too}')]
class HttpServerSketch extends StartMCPServer
{
    protected string $description = 'Serve an MCP server over Streamable HTTP on localhost';

    public function __construct(protected readonly McpHttpServer $server) {}

    public function boot(): void
    {
        $token = (string) ($this->option('token') ?: config('web.token') ?: McpHttpServer::token());
        $port = (int) ($this->option('port') ?: config('web.port', 8431));

        $this->server->start((string) $this->argument('server'), $port, $token, (string) config('web.host', '127.0.0.1'));

        fwrite(STDERR, "MCP over HTTP at {$this->server->url()}\nAuthorization: Bearer {$token}\n");
    }

    public function loop(array $mail = []): SketchLoopResult
    {
        return $this->server->running() ? SketchLoopResult::CONTINUE : SketchLoopResult::STOP;
    }

    public function shutdown(): void
    {
        $this->server->stop();
    }
}
