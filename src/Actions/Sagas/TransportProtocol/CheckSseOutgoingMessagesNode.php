<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use Illuminate\Support\Facades\Cache;
use JSONRPC\RPCNotification;
use JSONRPC\RPCRequest;
use JSONRPC\RPCResponse;
use MCP\Support\Facades\MCP;
use MCP\Transports\Stdio\Actions\Sagas\TransportProtocol\RequestDispatchNode;
use ProjectSaturnStudios\PocketFlow\Node;

class CheckSseOutgoingMessagesNode extends Node
{
    public function __construct(protected string $session_id)
    {
        parent::__construct();
    }

    public function prep(mixed &$shared): mixed
    {
        /*logger()->log('info', "ReceiveSseMessagesNode - prep: Sharing shared", [
            'shared' => $shared,
        ]);*/
        return $shared;
    }

    public function exec(mixed $prep_res): mixed
    {
        $results = false;

        $message = Cache::get("outgoing-message-from-{$this->session_id}", null);
        if($message)
        {
            $results = RPCRequest::from($message);
        }
        Cache::forget("outgoing-message-from-{$this->session_id}");

        return $results;
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        if($exec_res)
        {
            $shared['current_message'] = $exec_res;
            $this->next(new TransmitSseMessagesNode($this->session_id), 'dispatch');
            return 'dispatch';
        }

        usleep(10000);
        $this->next(new ReceiveSseMessagesNode($this->session_id), 'read');
        return 'read';
    }
}
