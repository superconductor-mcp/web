<?php

use Fabricate\Mechanism\Session\Actions\CancelledAction;
use Fabricate\Mechanism\Session\Actions\InitializedAction;
use SuperconductorMCP\SSE\Http\Controllers\MessagesController;
use SuperconductorMCP\SSE\Http\Controllers\SessionController;
use SuperconductorMCP\SSE\Http\Controllers\StreamController;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Http\Middleware\ValidateHost;
use SuperconductorMCP\SSE\Http\Middleware\ValidateProtocolVersion;

return [
    // the routes file loaded by the provider; null loads the package's own routes/web.php
    'routes' => null,

    // the MCP endpoint; {server} is the name of a registered MCPServer
    'path' => 'mcp/{server}',

    // the controller behind each method on the endpoint: POST messages, GET the server's stream, DELETE the session
    'controllers' => [
        'messages' => MessagesController::class,
        'stream' => StreamController::class,
        'session' => SessionController::class,
    ],

    // every entry runs on the endpoint, in this order, under the router alias mcp.<key>
    'middleware' => [
        'host' => ValidateHost::class,
        'protocol' => ValidateProtocolVersion::class,
        'session' => ResolveSession::class,
    ],

    // hosts the Host and Origin headers may name (DNS rebinding); '*' allows any
    'allowed_hosts' => ['localhost', '127.0.0.1', '[::1]'],

    'session' => [
        // seconds a session may go without a request or an open stream before it is forgotten
        'idle_timeout' => 1800,
        // seconds between two looks at the server's queued notifications on an open GET stream
        'poll_interval' => 0.1,
    ],

    'notifications' => [
        'notifications/initialized' => InitializedAction::class,
        'notifications/cancelled' => CancelledAction::class,
    ],
];
