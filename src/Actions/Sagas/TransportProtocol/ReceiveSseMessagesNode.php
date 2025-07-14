<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use Illuminate\Support\Facades\Cache;
use JSONRPC\RPCNotification;
use JSONRPC\RPCRequest;
use JSONRPC\RPCResponse;
use MCP\Support\Facades\MCP;
use ProjectSaturnStudios\PocketFlow\Node;

class ReceiveSseMessagesNode extends Node
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

        $message = Cache::get("incoming-message-for-{$this->session_id}", null);
        if($message)
        {
            $results = $message;
            logger()->log('info', "ReceiveSseMessagesNode - exec: Reading Msg", [
                'message' => $results,
            ]);
        }
        Cache::forget("incoming-message-for-{$this->session_id}");

        return $results;
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        if (!empty($exec_res))
        {
            $new_message = MCP::buildMessage($exec_res);
            if($new_message instanceof RPCResponse)
            {
                logger()->log('info', "ReceiveSseMessagesNode - post: Received Response", [
                    'response' => $new_message,
                ]);
            }
            elseif($new_message instanceof  RPCNotification)
            {
                logger()->log('info', "ReceiveSseMessagesNode - pos: Received Notification", [
                    'notification' => $new_message,
                ]);
            }
            elseif($new_message instanceof  RPCRequest)
            {
                $new_message = $new_message->additional(['session_id' => $this->session_id]);
                $shared['current_message'] = $new_message;
                $shared['incoming_messages'][] = $new_message;
                $this->next(new SseRequestDispatchNode($this->session_id), 'dispatch');
                return 'dispatch';
            }
            else
            {
                logger()->log('info', "ReceiveSseMessagesNode - post: Unknown Message", [
                    'unsupported' => $exec_res,
                ]);
            }
        }

        usleep(10000);
        //$this->next(new static($this->session_id), 'read');
        $this->next(new CheckSseOutgoingMessagesNode($this->session_id), 'outgoing');
        return 'outgoing';
    }
}
