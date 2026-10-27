<?php

// a stand-in 2025-03-26 Streamable HTTP server on one endpoint, /mcp. POST initialize answers JSON with Mcp-Session-Id: s1;
// every later request must carry it (400 without). Requests answer JSON; notifications and responses answer 202.
// GET answers 405; DELETE ends the session (404 from then on) and prints "DELETE <session>" on stdout.
// `--stream` answers each request as an event stream: a progress notification, then the response.
// `--listen` answers GET with a stream: event id 1 carries a server ping, then the stream is dropped; a GET with
// Last-Event-ID carries a log notification saying what it resumed from, and stays open.
// `--refuse` answers 401 to everything. `--expire` answers 404 to everything once the client has listed tools.

[$script, $port] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error) ?: exit(1);
echo "listening\n";
flush();

$clients = [];
$session = null;
$deleted = false;
$expired = false;
$drop_at = null;
$listener = null;

$respond = function ($socket, int $status, string $type, string $body, array $headers = []) use (&$clients): void {
    $reason = [200 => 'OK', 202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 405 => 'Method Not Allowed'][$status];
    $head = "HTTP/1.1 {$status} {$reason}\r\nContent-Type: {$type}\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n";

    foreach ($headers as $name => $value) {
        $head .= "{$name}: {$value}\r\n";
    }

    fwrite($socket, $head."\r\n".$body);
    fclose($socket);
    unset($clients[(int) $socket]);
};

$answer = function (array $message) {
    return match ($message['method'] ?? '') {
        'initialize' => ['result' => ['protocolVersion' => $message['params']['protocolVersion'], 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'http-server', 'version' => '1']]],
        'ping' => ['result' => []],
        'tools/list' => ['result' => ['tools' => [['name' => 'add', 'inputSchema' => ['type' => 'object']]]]],
        'tools/call' => ['result' => ['content' => [['type' => 'text', 'text' => (string) (($message['params']['arguments']['a'] ?? 0) + ($message['params']['arguments']['b'] ?? 0))]]]],
        default => ['error' => ['code' => -32601, 'message' => 'Method not found']],
    };
};

$deadline = time() + 30;

while (time() < $deadline) {
    if (! is_null($drop_at) && microtime(true) >= $drop_at && $listener) {
        fclose($listener);
        unset($clients[(int) $listener]);
        [$listener, $drop_at] = [null, null];
    }

    $read = [$server, ...array_column($clients, 0)];
    $w = $e = null;

    if (stream_select($read, $w, $e, 0, 100_000) < 1) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);
            $clients[(int) $client] = [$client, ''];
            continue;
        }

        $chunk = fread($socket, 65536);

        if ($chunk === '' || $chunk === false) {
            fclose($socket);
            unset($clients[(int) $socket]);

            if ($socket === $listener) {
                $listener = null;
            }

            continue;
        }

        $clients[(int) $socket][1] .= $chunk;
        $raw = &$clients[(int) $socket][1];
        $end = strpos($raw, "\r\n\r\n");

        if ($end === false) {
            continue;
        }

        $head = substr($raw, 0, $end);
        [$method, $target] = explode(' ', strtok($head, "\r\n"));
        preg_match('/content-length:\s*(\d+)/i', $head, $m);
        $length = (int) ($m[1] ?? 0);

        if (strlen($raw) < $end + 4 + $length) {
            continue;
        }

        $body = substr($raw, $end + 4, $length);
        $raw = substr($raw, $end + 4 + $length);
        preg_match('/mcp-session-id:\s*(\S+)/i', $head, $m);
        $sent_session = $m[1] ?? null;
        preg_match('/last-event-id:\s*(\S+)/i', $head, $m);
        $last_event_id = $m[1] ?? null;

        if (in_array('--refuse', $argv, true)) {
            $respond($socket, 401, 'text/plain', 'no');
            continue;
        }

        if (! str_starts_with($target, '/mcp')) {
            $respond($socket, 404, 'text/plain', 'not here');
            continue;
        }

        if ($deleted || $expired) {
            $respond($socket, 404, 'text/plain', 'session gone');
            continue;
        }

        if ($method === 'DELETE') {
            $deleted = true;
            echo "DELETE {$sent_session}\n";
            flush();
            $respond($socket, 200, 'text/plain', 'bye');
            continue;
        }

        if ($method === 'GET') {
            if (! in_array('--listen', $argv, true)) {
                $respond($socket, 405, 'text/plain', 'no stream');
                continue;
            }

            fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\n\r\n");
            $listener = $socket;

            if (is_null($last_event_id)) {
                fwrite($socket, "id: 1\nevent: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 'srv', 'method' => 'ping'])."\n\n");
                $drop_at = microtime(true) + 0.3;
            } else {
                fwrite($socket, "id: 2\nevent: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => "resumed from {$last_event_id}"]])."\n\n");
            }

            continue;
        }

        if ($method !== 'POST') {
            $respond($socket, 405, 'text/plain', 'no');
            continue;
        }

        $message = json_decode($body, true);
        $is_request = is_array($message) && isset($message['method']) && array_key_exists('id', $message);

        if (($message['method'] ?? '') === 'initialize') {
            $session = 's1';
            $respond($socket, 200, 'application/json', json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], ...$answer($message)]), ['Mcp-Session-Id' => $session]);
            continue;
        }

        if ($sent_session !== $session) {
            $respond($socket, 400, 'text/plain', 'session id missing');
            continue;
        }

        if (! $is_request) {
            $respond($socket, 202, 'text/plain', '');
            continue;
        }

        $reply = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], ...$answer($message)]);
        $expired = in_array('--expire', $argv, true) && $message['method'] === 'tools/list';

        if (in_array('--stream', $argv, true)) {
            $progress = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 'p', 'progress' => 1, 'total' => 2, 'message' => 'half way']]);
            fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\nevent: message\ndata: {$progress}\n\nevent: message\ndata: {$reply}\n\n");
            fclose($socket);
            unset($clients[(int) $socket]);
            continue;
        }

        $respond($socket, 200, 'application/json', $reply);
    }
}
