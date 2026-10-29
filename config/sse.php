<?php

use Fabricate\Mechanism\Session\Actions\CancelledAction;
use Fabricate\Mechanism\Session\Actions\InitializedAction;
use SuperconductorMCP\SSE\Http\Controllers\MessagesController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\AuthorizationServerMetadataController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\AuthorizeController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\LoginController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\RegisterController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\RevokeController;
use SuperconductorMCP\SSE\Http\Controllers\OAuth\TokenController;
use SuperconductorMCP\SSE\Http\Controllers\ProtectedResourceController;
use SuperconductorMCP\SSE\Http\Controllers\SessionController;
use SuperconductorMCP\SSE\Http\Controllers\StreamController;
use SuperconductorMCP\SSE\Http\Middleware\Authenticate;
use SuperconductorMCP\SSE\Http\Middleware\ResolveSession;
use SuperconductorMCP\SSE\Http\Middleware\ValidateHost;
use SuperconductorMCP\SSE\Http\Middleware\ValidateProtocolVersion;

return [
    // the routes file loaded by the provider; null loads the package's own routes/web.php
    'routes' => null,

    // the MCP endpoint; {server} is the name of a registered MCPServer
    'path' => 'mcp/{server}',

    // the controller behind each method on the endpoint: POST messages, GET the server's stream, DELETE the session;
    // metadata answers GET /.well-known/oauth-protected-resource/<path> when authorization is on; the oauth_*
    // controllers are the built-in authorization server's, routed only when it is enabled
    'controllers' => [
        'messages' => MessagesController::class,
        'stream' => StreamController::class,
        'session' => SessionController::class,
        'metadata' => ProtectedResourceController::class,
        'oauth_metadata' => AuthorizationServerMetadataController::class,
        'oauth_authorize' => AuthorizeController::class,
        'oauth_token' => TokenController::class,
        'oauth_register' => RegisterController::class,
        'oauth_revoke' => RevokeController::class,
        'oauth_login' => LoginController::class,
    ],

    // every entry runs on the endpoint, in this order, under the router alias mcp.<key>
    'middleware' => [
        'host' => ValidateHost::class,
        'auth' => Authenticate::class,
        'protocol' => ValidateProtocolVersion::class,
        'session' => ResolveSession::class,
    ],

    // hosts the Host and Origin headers may name (DNS rebinding); '*' allows any
    'allowed_hosts' => ['localhost', '127.0.0.1', '[::1]'],

    'authorization' => [
        // the auth guard that checks each request's bearer token; null leaves the endpoint open. 'mcp' is the
        // package's guard over mcp_access_tokens; name your own (a JWT or introspection guard) to take tokens elsewhere
        'guard' => env('MCP_AUTH_GUARD'),
        // the authorization servers that issue tokens, listed in the protected resource metadata
        'servers' => array_values(array_filter(explode(',', (string) env('MCP_AUTHORIZATION_SERVERS', '')))),
        // scopes every request's token must carry; advertised as scopes_supported
        'scopes' => [],

        // the built-in OAuth 2.1 authorization server: people sign in to this application and approve a client,
        // which gets tokens for the mcp guard. Enabling it makes 'mcp' the default guard and lists this app first
        // in authorization_servers
        'server' => [
            'enabled' => (bool) env('MCP_AUTHORIZATION_SERVER', false),
            // the endpoints live under this path: <prefix>/authorize, /token, /register, /revoke, /login
            'prefix' => 'oauth',
            // the session guard people sign in with to approve a client
            'session_guard' => 'web',
            // the app's own login page (a route name or a path); null uses the package's email and password page
            'login' => null,
            // dynamic client registration (RFC 7591) at <prefix>/register
            'registration' => true,
            // client_ids that are https URLs of a client metadata document (2025-11-25)
            'client_metadata_documents' => true,
            // lifetimes, in seconds
            'code_ttl' => 60,
            'access_ttl' => 3600,
            'refresh_ttl' => 2592000,
        ],
    ],

    'session' => [
        // seconds a session may go without a request or an open stream before it is forgotten
        'idle_timeout' => 1800,
        // seconds between two looks at the server's queued notifications on an open GET stream
        'poll_interval' => 0.1,
        // events each stream keeps after writing them, for a client that resumes it with Last-Event-ID
        'replay_events' => 100,
    ],

    'notifications' => [
        'notifications/initialized' => InitializedAction::class,
        'notifications/cancelled' => CancelledAction::class,
    ],
];
