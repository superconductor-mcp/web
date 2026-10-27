<?php

// a stand-in SSE server: GET /sse opens the stream and names the POST endpoint; POST /messages?session=1 takes
// one message, answers 202, and pushes the reply down the stream. `--refuse` answers 401 to everything;
// `--plain` answers the GET with text/plain; `--chunked` frames the stream with chunked transfer encoding.

[$script, $port] = $argv;
$chunked = in_array('--chunked', $argv, true);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error) ?: exit(1);
echo "listening\n";
flush();

$clients = [];
$stream = null;

$write = function ($socket, string $data) use (&$chunked, &$stream): void {
    fwrite($socket, $socket === $stream && $chunked ? dechex(strlen($data))."\r\n{$data}\r\n" : $data);
};

$deadline = time() + 30;

while (time() < $deadline) {
    $read = [$server, ...array_column($clients, 0)];
    $w = $e = null;

    if (stream_select($read, $w, $e, 0, 200_000) < 1) {
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
            if ($socket === $stream) { exit(0); }   // the client closed the stream: done
            fclose($socket);
            unset($clients[(int) $socket]);
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

        if (in_array('--refuse', $argv, true)) {
            fwrite($socket, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($socket);
            unset($clients[(int) $socket]);
            continue;
        }

        if ($method === 'GET' && str_starts_with($target, '/sse')) {
            $stream = $socket;
            $type = in_array('--plain', $argv, true) ? 'text/plain' : 'text/event-stream';
            fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\nCache-Control: no-cache\r\n".($chunked ? "Transfer-Encoding: chunked\r\n" : '')."\r\n");
            $write($socket, "event: endpoint\ndata: /messages?session=1\n\n");
            continue;
        }

        if ($method === 'POST' && str_starts_with($target, '/messages')) {
            fwrite($socket, "HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($socket);
            unset($clients[(int) $socket]);

            $message = json_decode($body, true);
            $answer = match ($message['method'] ?? '') {
                'initialize' => ['result' => ['protocolVersion' => $message['params']['protocolVersion'], 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'sse-server', 'version' => '1']]],
                'ping' => ['result' => []],
                'tools/list' => ['result' => ['tools' => [['name' => 'noop', 'inputSchema' => ['type' => 'object']]]]],
                'tools/call' => ['result' => ['content' => [['type' => 'text', 'text' => json_encode($message['params']['arguments'] ?? [])]]]],
                default => null,
            };

            if (! is_null($answer) && array_key_exists('id', $message) && $stream) {
                $write($stream, "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], ...$answer])."\n\n");
            }

            // the client can ask this server to ping it back, to prove it answers server requests over a POST
            if (($message['params']['name'] ?? '') === 'ping-me' && $stream) {
                $write($stream, "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'ping'])."\n\n");
            }

            continue;
        }

        fwrite($socket, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($socket);
        unset($clients[(int) $socket]);
    }
}
