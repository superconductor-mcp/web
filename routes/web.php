<?php

use Illuminate\Support\Facades\Route;

Route::get('/mcp/sse', \MCP\Transports\SSE\Http\Controllers\McpSseEndpoint::class."@entry")->name('mcp.sse');
Route::post('/mcp/sse/messages', \MCP\Transports\SSE\Http\Controllers\McpSseMessages::class)->name('mcp.sse.messages');
