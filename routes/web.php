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
