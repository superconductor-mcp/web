<?php

use Fabricate\Mechanism\Session\Actions\CancelledAction;
use Fabricate\Mechanism\Session\Actions\InitializedAction;

return [
    // the local MCP endpoint: loopback only; a harness on this machine connects to http://127.0.0.1:<port>/mcp
    'host' => '127.0.0.1',
    'port' => (int) env('MCP_HTTP_PORT', 8431),
    // the bearer every request must carry; empty lets `rocket mcp:http` mint one and print it
    'token' => env('MCP_HTTP_TOKEN', ''),
    // false: no bearer is asked for (the Origin check and loopback binding stay)
    'auth' => (bool) env('MCP_HTTP_AUTH', true),
    'limits' => [
        'head' => 16384,
        'body' => 1048576,
        // seconds a connection may take to send its request head before a 408
        'head_timeout' => 10,
    ],
    'session' => [
        // seconds without a request before a session is dropped
        'idle' => 300,
        // events kept per session so a dropped listening stream can resume with Last-Event-ID
        'replay' => 100,
    ],
    'notifications' => [
        'notifications/initialized' => InitializedAction::class,
        'notifications/cancelled' => CancelledAction::class,
    ],
];
