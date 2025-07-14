<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use ProjectSaturnStudios\PocketFlow\Node;

class InitializeSseTransportNode extends Node
{
    public function __construct(protected string $session_id)
    {
        parent::__construct();
    }

    public function prep(mixed &$shared): mixed
    {
        // @todo - load a session's data here
        return [
            'incoming_messages' => [],
            'outgoing_messages' => [],
            'current_message' => null,
        ];
    }

    public function exec(mixed $prep_res): mixed
    {
        $this->send_headers();

        return ['success' => true, 'message' => 'SSE stream setup successfully.'];
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        $shared = $prep_res;
        $this->next(new SseEndpointNode($this->session_id), 'endpoint');
        return 'endpoint';
    }

    protected function send_headers(): void
    {
        if (headers_sent($file, $line)) {
            $error_message = "[SSECommunicationLoopManager] Cannot initialize SSE stream: Headers already sent in {$file} on line {$line}.";
            logger()->log('critical', $error_message, []);
            throw new \RuntimeException($error_message);
        }

        $sse_headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable buffering for Nginx
        ];

        foreach($sse_headers as $header => $value) {
            header("$header: $value");
        }

        // Disable output buffering
        $this->clean_output_buffer();
        $this->override_execution_time();
        $this->boot_shutdown_function();
        flush();
    }

    private function clean_output_buffer() : void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
    }

    private function override_execution_time() : void
    {
        // Set time limit if specified
        logger()->log('info', "Setting time limit to " . config('mcp-transports.http-sse.max_time_limit', 30));
        //set_time_limit(config('mcp-transports.http-sse.max_time_limit', 30));
        ini_set('max_execution_time', 65);
        set_time_limit(65);

    }

    private function boot_shutdown_function() : void
    {
        register_shutdown_function(function () {
            //exit();
        });
    }
}
