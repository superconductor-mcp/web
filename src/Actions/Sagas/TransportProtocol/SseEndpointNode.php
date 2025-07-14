<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use Illuminate\Http\StreamedEvent;
use ProjectSaturnStudios\PocketFlow\Node;

class SseEndpointNode extends Node
{
    public function __construct(protected string $session_id)
    {
        parent::__construct();
    }

    public function prep(mixed &$shared): mixed
    {
        return null;
    }

    public function exec(mixed $prep_res): mixed
    {
        $output = "event: endpoint\n";
        $output .= "data: /mcp/sse/messages?session_id={$this->session_id}\n\n";
        echo $output;
        if (ob_get_level() > 0) {ob_flush();}
        flush();
        return null;
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        $this->next(new ReceiveSseMessagesNode($this->session_id), 'read');
        return 'read';
    }
}
