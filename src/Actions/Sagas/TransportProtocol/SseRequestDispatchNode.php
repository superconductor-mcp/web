<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use JSONRPC\Support\Facades\RPCRouter;
use ProjectSaturnStudios\PocketFlow\Node;

class SseRequestDispatchNode extends Node
{
    public function __construct(protected string $session_id)
    {
        parent::__construct();
    }

    public function prep(mixed &$shared): mixed
    {
        logger()->log('info', "SseRequestDispatchNode - prep: Processing Message", [
            'shared' => $shared,
        ]);

        return $shared['current_message'];
    }

    public function exec(mixed $prep_res): mixed
    {
        return RPCRouter::dispatch($prep_res);
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        $shared['current_message'] = $exec_res;
        $shared['outgoing_messages'][] = $exec_res;
        logger()->log('info', "SseRequestDispatchNode - post: Processing Response", [
            'message' => $exec_res,
        ]);
        $this->next(new TransmitSseMessagesNode($this->session_id), 'send');
        return 'send';
    }
}
