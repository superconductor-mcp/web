<?php

namespace MCP\Transports\SSE\Actions\Sagas\TransportProtocol;

use ProjectSaturnStudios\PocketFlow\Node;

class TransmitSseMessagesNode extends Node
{
    public function __construct(protected string $session_id)
    {
        parent::__construct();
    }

    public function prep(mixed &$shared): mixed
    {
        logger()->log('info', "TransmitSseMessagesNode - prep: Setting up shared", [
            'outgoing_message' => $shared['current_message']->toJsonRpc(),
        ]);
        return $shared['current_message'];
    }

    public function exec(mixed $prep_res): mixed
    {
        echo $this->prepare_output($prep_res->toJsonRpc(), 'message');
        if (ob_get_level() > 0) {ob_flush();}
        flush();
        return null;
    }

    public function post(mixed &$shared, mixed $prep_res, mixed $exec_res): mixed
    {
        // @todo - save everything to session
        $shared['current_message'] = null;
        $this->next(new ReceiveSseMessagesNode($this->session_id), 'read');
        return 'read';
    }

    private function prepare_output(string|array $payload, ?string $event_to_send = null, ?string $id = null) : string
    {
        // Convert data to JSON if it's an array or object
        $data = is_string($payload) ? $payload : json_encode($payload);

        // Format the event
        $output = '';

        if ($id) {
            $output .= "id: {$id}\n";
        }

        if ($event_to_send) {
            $output .= "event: {$event_to_send}\n";
        }

        // Split data by newlines to ensure proper formatting
        foreach (explode("\n", $data) as $line) {
            $output .= "data: {$line}\n\n";
        }

        // Send the event
        return $output;
    }
}
