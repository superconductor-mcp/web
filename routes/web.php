<?php

use Mariner\NutsAndBolts\Facades\Route;

// Everything here comes from config/sse.php: the path, the controller per method, and the middleware aliases.
Route::middleware(array_map(fn (string $key) => "mcp.{$key}", array_keys(config('sse.middleware', []))))
    ->group(function () {
        $path = config('sse.path');

        Route::post($path, config('sse.controllers.messages'))->name('mcp.messages');
        Route::get($path, config('sse.controllers.stream'))->name('mcp.stream');
        Route::delete($path, config('sse.controllers.session'))->name('mcp.session');
    });

// RFC 9728: the endpoint's metadata sits at the well-known path with the endpoint's path appended, open to anyone
if (! is_null(config('sse.authorization.guard'))) {
    Route::get('.well-known/oauth-protected-resource/'.trim(config('sse.path'), '/'), config('sse.controllers.metadata'))->name('mcp.metadata');
}

// The built-in authorization server. People sign in and approve on session pages (the web group: cookies, CSRF);
// clients call the token, registration and revocation endpoints directly, so those carry no session and are throttled.
if (config('sse.authorization.server.enabled')) {
    $prefix = trim((string) config('sse.authorization.server.prefix', 'oauth'), '/');

    Route::get('.well-known/oauth-authorization-server', config('sse.controllers.oauth_metadata'))->name('mcp.oauth.metadata');

    Route::middleware('web')->group(function () use ($prefix) {
        Route::get("{$prefix}/authorize", [config('sse.controllers.oauth_authorize'), 'show'])->name('mcp.oauth.authorize');
        Route::post("{$prefix}/authorize", [config('sse.controllers.oauth_authorize'), 'decide'])->name('mcp.oauth.decide');

        if (is_null(config('sse.authorization.server.login'))) {
            Route::get("{$prefix}/login", [config('sse.controllers.oauth_login'), 'show'])->name('mcp.oauth.login');
            Route::post("{$prefix}/login", [config('sse.controllers.oauth_login'), 'login'])->middleware('throttle:5,1');
        }
    });

    Route::post("{$prefix}/token", config('sse.controllers.oauth_token'))->middleware('throttle:60,1')->name('mcp.oauth.token');
    Route::post("{$prefix}/revoke", config('sse.controllers.oauth_revoke'))->middleware('throttle:60,1')->name('mcp.oauth.revoke');

    if (config('sse.authorization.server.registration', true)) {
        Route::post("{$prefix}/register", config('sse.controllers.oauth_register'))->middleware('throttle:10,1')->name('mcp.oauth.register');
    }
}
