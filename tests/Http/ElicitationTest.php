<?php

// A tool that asks the user a question waits in its request; the answer arrives as another POST.
// Both are in flight at once, so the server must run more than one request at a time (max_in_flight above 1 is serve's default).

it('puts the question on the POST stream, takes the answer in a second POST, and finishes the first', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server, capabilities: ['elicitation' => (object) []]);
    mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    $call = clientOf($this, $server);
    stream_set_blocking($call, true);
    fwrite($call, mcpRaw($server, 'POST', '/mcp/fixture', mcpSession($id), rpc(1, 'tools/call', ['name' => 'ask', 'arguments' => (object) []])));
    stream_set_blocking($call, false);

    $asked = mcpRead($this, [$call], fn (array $r) => mcpSaw($r[0], 'elicitation/create'))[0];
    $question = collect(mcpAnswer($asked)['events'])->firstWhere('method', 'elicitation/create');

    expect($question['params']['message'])->toBe('Your name?')
        ->and($question['params']['requestedSchema']['required'])->toBe(['name']);

    $answered = mcp($this, $server, 'POST', mcpSession($id), [
        'jsonrpc' => '2.0', 'id' => $question['id'], 'result' => ['action' => 'accept', 'content' => ['name' => 'Ada']],
    ]);

    expect($answered['status'])->toBe(202);

    // the stream so far, and the rest of it once the server closes
    $finished = mcpAnswer($asked.mcpRead($this, [$call], fn () => feof($call))[0]);
    $result = collect($finished['events'])->firstWhere('id', 1);

    expect($finished['status'])->toBe(200)
        ->and($result['result']['content'][0]['text'])->toBe('hello Ada');
});

it('finishes the call with the user\'s decline', function () {
    $server = serving($this);
    [$id] = mcpInitialize($this, $server, capabilities: ['elicitation' => (object) []]);
    mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    $call = clientOf($this, $server);
    stream_set_blocking($call, true);
    fwrite($call, mcpRaw($server, 'POST', '/mcp/fixture', mcpSession($id), rpc(1, 'tools/call', ['name' => 'ask', 'arguments' => (object) []])));
    stream_set_blocking($call, false);

    $asked = mcpRead($this, [$call], fn (array $r) => mcpSaw($r[0], 'elicitation/create'))[0];
    $question = collect(mcpAnswer($asked)['events'])->firstWhere('method', 'elicitation/create');

    mcp($this, $server, 'POST', mcpSession($id), ['jsonrpc' => '2.0', 'id' => $question['id'], 'result' => ['action' => 'decline']]);

    $finished = mcpAnswer($asked.mcpRead($this, [$call], fn () => feof($call))[0]);

    expect(collect($finished['events'])->firstWhere('id', 1)['result']['content'][0]['text'])->toBe('decline');
});
